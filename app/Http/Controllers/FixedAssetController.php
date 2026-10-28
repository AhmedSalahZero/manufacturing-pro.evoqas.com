<?php

namespace App\Http\Controllers;

use App\Calculations\Timeline;
use App\Http\Requests\UpdateFixedAssetsRequest;
use App\Models\Project;
use App\Services\FixedAssetService;
use App\Services\SalesCalculationService;
use App\Support\StudyCalendar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — FixedAssetController (fixed assets and their loans)
//  Location: app/Http/Controllers/FixedAssetController.php
//
//  edit    the fixed assets form   /projects/{project}/fixed-assets
//  update  save it (and recalculate the study)   PUT /projects/{project}/fixed-assets
//  The saving and the numbers are done by App\Services\FixedAssetService.
// ══════════════════════════════════════════════════════════════════

class FixedAssetController extends Controller
{
    public function __construct(
        private readonly FixedAssetService $assets,
        private readonly SalesCalculationService $calculations,
    ) {
    }

    public function edit(Request $request, Project $project): Response
    {
        Gate::authorize('view', $project);

        $project->load('products');
        $dates = $project->extended_study_dates ?? [];
        $rows = $project->fixedAssets()->with('loanSchedulePayments')->orderBy('id')->get();
        $timeline = $this->calculations->timeline($project);

        return Inertia::render('FixedAssets/Edit', [
            'project'  => [
                'id'        => $project->id,
                'name'      => $project->name,
                'newCompany' => (bool) $project->new_company,
                'startYear' => (int) $project->start_date->format('Y'),
                'endYear'   => (int) ($project->end_date ?? $project->start_date)->format('Y'),
            ],
            'products' => $project->products->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->values(),
            'rows'     => $rows->map(function ($a) use ($dates, $project) {
                $custom = [];
                $fromTotal = (array) $a->from_total_or_executions;
                foreach ((array) $a->custom_collection_policy as $days => $rate) {
                    $custom[] = ['due_days' => (int) $days, 'rate' => (float) $rate, 'from_total' => (bool) ($fromTotal[$days] ?? false)];
                }
                if ($custom === []) {
                    $custom[] = ['due_days' => '', 'rate' => '', 'from_total' => false];
                }

                $typed = (array) $a->product_allocations;
                $allocations = [];
                foreach ($project->products as $p) {
                    $allocations[$p->id] = (float) ($typed[$p->id] ?? 0);
                }

                return [
                    'id'                            => $a->id,
                    'name'                          => $a->name,
                    'counts'                        => (int) $a->counts,
                    'amount'                        => (float) $a->amount,
                    'depreciation_duration'         => (int) $a->depreciation_duration,
                    'start'                         => StudyCalendar::monthAt($dates, $a->start_date) ?? '',
                    'end'                           => StudyCalendar::monthAt($dates, $a->end_date) ?? '',
                    'admin_depreciation_percentage' => (float) $a->admin_depreciation_percentage,
                    'replacement_cost_rate'         => (float) $a->replacement_cost_rate,
                    'replacement_cost_interval'     => (int) ($a->replacement_cost_interval ?: 1),
                    'payment_terms'                 => $a->payment_terms ?: 'cash',
                    'custom'                        => $custom,
                    'reservation_rate'              => (float) $a->reservation_rate,
                    'contractual_rate'              => (float) $a->contractual_rate,
                    'after_months'                  => (int) $a->after_months,
                    'installment_grace_period'      => (int) $a->installment_grace_period,
                    'installment_count'             => (int) ($a->installment_count ?: 1),
                    'payment_installment_interval'  => $a->payment_installment_interval ?: 'monthly',
                    'equity_funding_rate'           => (float) $a->equity_funding_rate,
                    'interest_rate'                 => (float) $a->interest_rate,
                    'grace_period'                  => (int) $a->grace_period,
                    'tenor'                         => (int) ($a->tenor ?: 60),
                    'installment_interval'          => $this->loanInterval((string) $a->installment_interval),
                    'is_as_revenue_percentages'     => (bool) $a->is_as_revenue_percentages,
                    'allocations'                   => (object) $allocations,
                ];
            })->values(),
            'summary'  => (object) $rows->mapWithKeys(fn ($a) => [$a->id => $this->summary($a, $dates)])->all(),
            'results'  => $this->results($rows, $timeline),
            'options'  => [
                'dueDays'           => FixedAssetService::DUE_DAYS,
                'slots'             => FixedAssetService::CUSTOM_SLOTS,
                'loanIntervals'     => FixedAssetService::LOAN_INTERVALS,
                'paymentIntervals'  => FixedAssetService::PAYMENT_INTERVALS,
            ],
            'readOnly' => ! $request->user()->can('update', $project),
        ]);
    }

    public function update(UpdateFixedAssetsRequest $request, Project $project): RedirectResponse
    {
        $this->assets->save($project, $request->validated());

        return match ($request->input('after')) {
            'list'  => redirect()->route('projects.index')->with('success', __('costs.assetsSaved')),
            // An existing company goes on to its opening balances; a new company starts from zero (the financial statements come next).
            'next'  => ($project->new_company ? redirect()->route('projects.index') : redirect()->route('projects.opening.edit', $project))->with('success', __('costs.assetsSaved')),
            default => redirect()->route('projects.assets.edit', $project)->with('success', __('costs.assetsSaved')),
        };
    }

    /** The old spellings ("quartly", "semi annually") still show correctly. */
    private function loanInterval(string $interval): string
    {
        return match ($interval) {
            'quarterly', 'quartly'           => 'quarterly',
            'semi-annually', 'semi annually' => 'semi-annually',
            default                          => 'monthly',
        };
    }

    /**
     * What the calculation found for one asset: its cost, the bank loan, the interest added to the cost while it was built,
     * and the month it was put into use.
     *
     * @return array<string,mixed>
     */
    private function summary($asset, array $dates): array
    {
        $loan = (array) $asset->ffe_loan_withdrawal_end_balance;
        $capitalised = (array) (($asset->capitalization_statement ?? [])['capitalized_interest'] ?? []);
        $transferred = (array) (($asset->capitalization_statement ?? [])['transferred_date_and_vales'] ?? []);

        return [
            'cost'        => (float) $asset->counts * (float) $asset->amount,
            'loan'        => $loan === [] ? 0.0 : (float) end($loan),
            'capitalized' => (float) array_sum($capitalised),
            'transferred' => $transferred === [] ? null : StudyCalendar::monthAt($dates, (int) array_key_last($transferred)),
            'value'       => $transferred === [] ? 0.0 : (float) end($transferred),
        ];
    }

    /**
     * Per asset and calendar year: depreciation and payments of the year, the net book value and the loan balance at the
     * end of the year.
     *
     * @return array<string,mixed>
     */
    private function results($rows, ?Timeline $timeline): array
    {
        if (! $timeline) {
            return ['years' => [], 'rows' => (object) []];
        }

        $lastMonth = [];
        foreach ($timeline->dates as $index => $date) {
            $lastMonth[(int) substr($date, 0, 4)] = $index;
        }
        $years = array_keys($lastMonth);
        $result = [];

        foreach ($rows as $asset) {
            $depreciation = (array) (($asset->depreciation_statement ?? [])['total_monthly_depreciation'] ?? []);
            $netValue = (array) (($asset->depreciation_statement ?? [])['end_balance'] ?? []);
            $payments = (array) $asset->ffe_payment;
            $loan = $asset->loanSchedulePayments->first();
            $loanBalance = $loan ? (array) json_decode((string) $loan->endBalance, true) : [];

            foreach ($timeline->dates as $month => $date) {
                $year = (int) substr($date, 0, 4);
                $result[$asset->id][$year]['depreciation'] = ($result[$asset->id][$year]['depreciation'] ?? 0) + (float) ($depreciation[$month] ?? 0);
                $result[$asset->id][$year]['payments'] = ($result[$asset->id][$year]['payments'] ?? 0) + (float) ($payments[$month] ?? 0);

                if ($month === $lastMonth[$year]) {
                    $result[$asset->id][$year]['net'] = (float) ($netValue[$month] ?? 0);
                    $result[$asset->id][$year]['loan'] = (float) ($loanBalance[$month] ?? 0);
                }
            }
        }

        return ['years' => $years, 'rows' => (object) $result];
    }
}
