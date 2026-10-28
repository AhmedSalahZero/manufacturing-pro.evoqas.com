<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateOpeningBalancesRequest;
use App\Calculations\OpeningBalanceCalculator;
use App\Models\Project;
use App\Services\OpeningBalanceService;
use App\Services\SalesCalculationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — OpeningBalanceController (what an existing company has on day one)
//  Location: app/Http/Controllers/OpeningBalanceController.php
//
//  edit    the opening balances form   /projects/{project}/opening-balances   (a new company has none: 404)
//  update  save it (and recalculate the study)   PUT /projects/{project}/opening-balances
//  The saving and the numbers are done by App\Services\OpeningBalanceService.
// ══════════════════════════════════════════════════════════════════

class OpeningBalanceController extends Controller
{
    public function __construct(
        private readonly OpeningBalanceService $balances,
        private readonly SalesCalculationService $calculations,
    ) {
    }

    public function edit(Request $request, Project $project): Response
    {
        Gate::authorize('view', $project);
        abort_if($project->new_company, 404);

        $project->load(['products', 'fixedAssetOpeningBalances', 'cashAndBankOpeningBalances', 'otherDebtorsOpeningBalances', 'supplierPayableOpeningBalances',
            'otherCreditsOpeningBalances', 'vatAndCreditWithholdTaxOpeningBalances', 'longTermLoanOpeningBalances', 'otherLongTermLiabilitiesOpeningBalances',
            'equityOpeningBalances']);

        $timeline = $this->calculations->timeline($project);
        $studyEnd = $timeline ? $this->balances->studyEnd($project, $timeline) : 0;

        $months = [];
        if ($timeline) {
            foreach ($timeline->dates as $index => $date) {
                if ($index <= $studyEnd) {
                    $months[] = ['index' => $index, 'year' => (int) substr($date, 0, 4), 'month' => (int) substr($date, 5, 2)];
                }
            }
        }

        $series = fn ($values) => (object) collect((array) $values)->map(fn ($v) => (float) $v)->all();
        $num = fn ($v) => (float) $v;

        $assets = $project->fixedAssetOpeningBalances->sortBy('id')->values()->map(function ($a) use ($project, $num) {
            $typed = (array) $a->product_allocations;
            $allocations = [];
            foreach ($project->products as $p) {
                $allocations[$p->id] = (float) ($typed[$p->id] ?? 0);
            }

            return [
                'id'                            => $a->id,
                'name'                          => $a->name,
                'gross_amount'                  => $num($a->gross_amount),
                'accumulated_depreciation'      => $num($a->accumulated_depreciation),
                'monthly_depreciation'          => $num($a->monthly_depreciation),
                'monthly_counts'                => (int) $a->monthly_counts,
                'admin_depreciation_percentage' => $num($a->admin_depreciation_percentage),
                'is_as_revenue_percentages'     => (bool) $a->is_as_revenue_percentages,
                'allocations'                   => (object) $allocations,
            ];
        });

        $cash = $project->cashAndBankOpeningBalances->sortBy('id')->first();
        $suppliers = $project->supplierPayableOpeningBalances->sortBy('id')->first();
        $taxes = $project->vatAndCreditWithholdTaxOpeningBalances->sortBy('id')->first();
        $equity = $project->equityOpeningBalances->sortBy('id')->first();

        $settled = fn ($rows) => $rows->sortBy('id')->values()->map(fn ($r) => [
            'id' => $r->id, 'name' => $r->name ?? '', 'amount' => $num($r->amount), 'settlements' => $series($r->payload),
        ]);

        return Inertia::render('OpeningBalances/Edit', [
            'project'   => [
                'id'        => $project->id,
                'name'      => $project->name,
                'startYear' => (int) $project->start_date->format('Y'),
                'endYear'   => (int) ($project->end_date ?? $project->start_date)->format('Y'),
            ],
            'months'    => $months,
            'products'  => $project->products->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->values(),
            'data'      => [
                'fixed_assets'    => $assets,
                'cash'            => [
                    'cash_and_bank_amount'       => $num($cash?->cash_and_bank_amount),
                    'customer_receivable_amount' => $num($cash?->customer_receivable_amount),
                    'settlements'                => $series($cash?->payload),
                ],
                'other_debtors'   => $settled($project->otherDebtorsOpeningBalances),
                'suppliers'       => ['amount' => $num($suppliers?->amount), 'settlements' => $series($suppliers?->payload)],
                'other_creditors' => $settled($project->otherCreditsOpeningBalances),
                'taxes'           => [
                    'vat_amount'              => $num($taxes?->vat_amount),
                    'credit_withhold_taxes'   => $num($taxes?->credit_withhold_taxes),
                    'corporate_taxes_payable' => $num($taxes?->corporate_taxes_payable),
                ],
                'loans'           => $project->longTermLoanOpeningBalances->sortBy('id')->values()->map(fn ($r) => [
                    'id' => $r->id, 'amount' => $num($r->amount), 'installments' => $series($r->installments), 'interests' => $series($r->interests),
                ]),
                'other_long_term' => $project->otherLongTermLiabilitiesOpeningBalances->sortBy('id')->values()->map(fn ($r) => [
                    'id' => $r->id, 'amount' => $num($r->amount), 'settlements' => $series($r->payload),
                ]),
                'equity'          => [
                    'paid_up_capital_amount' => $num($equity?->paid_up_capital_amount),
                    'legal_reserve'          => $num($equity?->legal_reserve),
                    'retained_earnings'      => $num($equity?->retained_earnings),
                ],
            ],
            'inventory' => $this->balances->inventory($project),
            'readOnly'  => ! $request->user()->can('update', $project),
        ]);
    }

    public function update(UpdateOpeningBalancesRequest $request, Project $project): RedirectResponse
    {
        abort_if($project->new_company, 404);

        // Nothing is kept unless the opening balances balance: everything is saved inside one transaction that is
        // undone (and the person told by how much the figures differ) when assets and liabilities plus equity differ.
        DB::transaction(function () use ($request, $project) {
            $this->balances->save($project, $request->validated());

            $balance = OpeningBalanceCalculator::balance($this->balances->totals($project->refresh()));

            if (! $balance['balanced']) {
                throw ValidationException::withMessages([
                    'balance' => __('opening.unbalanced', ['amount' => number_format(abs($balance['difference']))]),
                ]);
            }
        });

        $target = $request->input('after') === 'list'
            ? redirect()->route('projects.index')
            : redirect()->route('projects.opening.edit', $project);

        return $target->with('success', __('opening.saved'));
    }
}
