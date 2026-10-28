<?php

namespace App\Services;

use App\Calculations\FixedAssetCalculator;
use App\Calculations\ProductAllocation;
use App\Calculations\Timeline;
use App\Models\FixedAsset;
use App\Models\Project;
use App\Support\StudyCalendar;
use Illuminate\Support\Facades\DB;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — FixedAssetService (the fixed assets form and its calculation)
//  Location: app/Services/FixedAssetService.php
//
//  save()         saves what is typed on the fixed assets form, then recalculates the whole study
//                 (SalesCalculationService, which ends with recalculate() below).
//                 Rows sent with an id are updated, rows without an id are created, rows no longer sent are deleted.
//                 Months are typed as "YYYY-MM" and saved as a position in the study (0 = the study's first month).
//  recalculate()  runs the engine (app/Calculations/FixedAssetCalculator + LoanCalculator) for every asset and saves
//                 next to the inputs: the monthly statements (execution, payments, equity, loan drawdown, capitalisation,
//                 depreciation, payable), the loan schedule (loan_schedule_payments) and the depreciation that belongs
//                 to each product (product_expense_allocations, is_depreciation = 1).
// ══════════════════════════════════════════════════════════════════

class FixedAssetService
{
    public const PAYMENT_TERMS = ['cash', 'customize', 'installment'];

    /** Instalment intervals of the bank loan. */
    public const LOAN_INTERVALS = ['monthly', 'quarterly', 'semi-annually'];

    /** Instalment intervals of the supplier payment. */
    public const PAYMENT_INTERVALS = ['monthly', 'quarterly', 'semi-annually', 'annually'];

    /** Days after which a part of the price can be paid (0 = the same month). */
    public const DUE_DAYS = [0, 15, 30, 45, 60, 90, 120, 150, 180, 210, 240, 270, 300, 330, 360];

    /** The most payment lines one asset can have. */
    public const CUSTOM_SLOTS = 5;

    public const LOAN_TYPE = 'grace_period_without_capitalization';

    public function __construct(private readonly SalesCalculationService $calculations)
    {
    }

    /** @param array<string,mixed> $data already validated (see UpdateFixedAssetsRequest) */
    public function save(Project $project, array $data): void
    {
        DB::transaction(function () use ($project, $data) {
            $dates = $project->extended_study_dates ?? [];
            $productIds = $project->products()->pluck('id')->all();
            $kept = [];

            foreach ($data['rows'] ?? [] as $row) {
                $terms = $row['payment_terms'] ?? 'cash';
                $byRevenue = (bool) ($row['is_as_revenue_percentages'] ?? true);
                $admin = (float) ($row['admin_depreciation_percentage'] ?? 0);
                [$policy, $fromTotal] = $terms === 'customize' ? $this->customPolicy($row['custom'] ?? []) : [[], []];

                $values = [
                    'name'                           => $row['name'],
                    'counts'                         => (int) $row['counts'],
                    'amount'                         => $row['amount'],
                    'depreciation_duration'          => (int) ($row['depreciation_duration'] ?? 0),
                    'start_date'                     => StudyCalendar::indexOf($dates, $row['start']) ?? 0,
                    'end_date'                       => StudyCalendar::indexOf($dates, $row['end']) ?? 0,
                    'admin_depreciation_percentage'  => $admin,
                    'manufacturing_depreciation_percentage' => 100 - $admin,
                    'replacement_cost_rate'          => $row['replacement_cost_rate'] ?? 0,
                    'replacement_cost_interval'      => (int) ($row['replacement_cost_interval'] ?? 1),
                    'payment_terms'                  => $terms,
                    'custom_collection_policy'       => $policy,
                    'from_total_or_executions'       => $fromTotal,
                    'reservation_rate'               => $terms === 'installment' ? ($row['reservation_rate'] ?? 0) : 0,
                    'contractual_rate'               => $terms === 'installment' ? ($row['contractual_rate'] ?? 0) : 0,
                    'after_months'                   => $terms === 'installment' ? (int) ($row['after_months'] ?? 0) : 0,
                    'installment_grace_period'       => $terms === 'installment' ? (int) ($row['installment_grace_period'] ?? 0) : 0,
                    'installment_count'              => $terms === 'installment' ? max(1, (int) ($row['installment_count'] ?? 1)) : 1,
                    'payment_installment_interval'   => $terms === 'installment' ? ($row['payment_installment_interval'] ?? 'monthly') : 'monthly',
                    'equity_funding_rate'            => $row['equity_funding_rate'] ?? 100,
                    'interest_rate'                  => $row['interest_rate'] ?? 0,
                    'grace_period'                   => (int) ($row['grace_period'] ?? 0),
                    'tenor'                          => (int) ($row['tenor'] ?? 60),
                    'installment_interval'           => $row['installment_interval'] ?? 'monthly',
                    'is_as_revenue_percentages'      => $byRevenue ? 1 : 0,
                    'product_allocations'            => $byRevenue ? [] : $this->percentages($row['allocations'] ?? [], $productIds),
                ];

                $model = ! empty($row['id']) ? FixedAsset::where('project_id', $project->id)->find($row['id']) : null;

                if ($model) {
                    $model->update($values);
                } else {
                    $model = $project->fixedAssets()->create($values);
                }
                $kept[] = $model->id;
            }

            FixedAsset::where('project_id', $project->id)->whereNotIn('id', $kept)->delete();
        });

        $this->calculations->recalculate($project->refresh());
    }

    /**
     * "due days => %" and "due days => is it a % of the total cost" from the lines of the payment pop-up.
     *
     * @return array{0:array<int,float>,1:array<int,int>}
     */
    public function customPolicy(array $lines): array
    {
        $policy = [];
        $fromTotal = [];

        foreach ($lines as $line) {
            if (($line['rate'] ?? '') === '' || ! isset($line['due_days']) || $line['due_days'] === '') {
                continue;
            }
            $days = (int) $line['due_days'];
            $policy[$days] = (float) $line['rate'];
            $fromTotal[$days] = ! empty($line['from_total']) ? 1 : 0;
        }

        ksort($policy);
        ksort($fromTotal);

        return [$policy, $fromTotal];
    }

    /** Runs the engine for every asset of the study and saves the results. */
    public function recalculate(Project $project, Timeline $timeline): void
    {
        DB::transaction(function () use ($project, $timeline) {
            $project->load(['products' => fn ($q) => $q->orderBy('id'), 'fixedAssets' => fn ($q) => $q->orderBy('id')]);

            $values = [];
            $sellingStart = [];
            foreach ($project->products as $product) {
                $values[$product->id] = (array) $product->monthly_sales_target_values;
                $sellingStart[$product->id] = (int) $product->selling_start_date;
            }

            $revenueShares = ProductAllocation::revenueShares($values);
            $months = array_keys($timeline->dates);
            $productIds = $project->products->pluck('id')->all();
            $operation = array_key_first($timeline->operationMonths) ?? 0;
            $studyEnd = ($project->end_date ? StudyCalendar::indexOf($timeline->dates, $project->end_date->format('Y-m-d')) : null) ?? max($months);

            DB::table('product_expense_allocations')->where('project_id', $project->id)->where('is_depreciation', 1)->delete();
            DB::table('loan_schedule_payments')->where('project_id', $project->id)->delete();

            $allocations = [];

            foreach ($project->fixedAssets as $asset) {
                $result = FixedAssetCalculator::calculate($this->inputs($asset), $timeline, $operation, $studyEnd);

                $byRevenue = (bool) $asset->is_as_revenue_percentages;
                $typed = ProductAllocation::typedShares(array_map('floatval', (array) $asset->product_allocations), $sellingStart, $months);

                $asset->update([
                    'capitalization_statement'                    => $result['under_progress'],
                    'depreciation_statement'                      => $result['depreciation'],
                    'statement'                                   => $result['depreciation'],
                    'admin_depreciations'                         => $result['administration_depreciation'],
                    'ffe_equity_payment'                          => $result['equity_payment'],
                    'ffe_loan_withdrawal'                         => $result['loan_withdrawal'],
                    'ffe_loan_withdrawal_end_balance'             => $result['loan_withdrawal_end_balance'],
                    'ffe_payment'                                 => $result['payments'],
                    'ffe_execution_and_payment'                   => $result['execution'],
                    'ffe_payable'                                 => $result['payable'],
                    'loan_capitalized_interests'                  => $result['loan_withdrawal_interest'],
                    'income_statement_loan_capitalized_interests' => $result['income_statement_interest'],
                    'monthly_product_allocations'                 => $byRevenue ? [] : $typed,
                ]);

                if ($result['schedule'] !== []) {
                    $schedule = $result['schedule'];
                    DB::table('loan_schedule_payments')->insert([
                        'fixed_asset_id'  => $asset->id,
                        'project_id'      => $project->id,
                        'month_as_index'  => $result['loan_start_index'],
                        'loan_type'       => self::LOAN_TYPE,
                        'beginning'       => json_encode($schedule['beginning']),
                        'interestAmount'  => json_encode($schedule['interestAmount']),
                        'schedulePayment' => json_encode($schedule['schedulePayment']),
                        'principleAmount' => json_encode($schedule['principleAmount']),
                        'endBalance'      => json_encode($result['schedule_end_balance']),
                        'created_at'      => now(),
                        'updated_at'      => now(),
                    ]);
                }

                if ($result['manufacturing_depreciation'] !== [] && $asset->name !== '') {
                    $perProduct = ProductAllocation::split($revenueShares, $typed, $productIds, $result['manufacturing_depreciation'], $byRevenue);
                    foreach ($perProduct as $productId => $payload) {
                        $allocations[] = [
                            'project_id' => $project->id, 'product_id' => $productId, 'expense_name' => $asset->name,
                            'is_expense' => 0, 'is_depreciation' => 1, 'payload' => json_encode($payload),
                            'created_at' => now(), 'updated_at' => now(),
                        ];
                    }
                }
            }

            if ($allocations !== []) {
                DB::table('product_expense_allocations')->insert($allocations);
            }
        });
    }

    /** What the engine needs, from the saved row. @return array<string,mixed> */
    public function inputs(FixedAsset $asset): array
    {
        $policy = [];
        foreach ((array) $asset->custom_collection_policy as $days => $rate) {
            $policy[(int) $days] = (float) $rate;
        }
        $fromTotal = [];
        foreach ((array) $asset->from_total_or_executions as $days => $flag) {
            $fromTotal[(int) $days] = (bool) $flag;
        }

        return [
            'count'                => (float) $asset->counts,
            'amount'               => (float) $asset->amount,
            'start'                => (int) $asset->start_date,
            'end'                  => (int) $asset->end_date,
            'depreciation_years'   => (int) $asset->depreciation_duration,
            'admin_pct'            => (float) $asset->admin_depreciation_percentage,
            'manufacturing_pct'    => (float) $asset->manufacturing_depreciation_percentage,
            'replacement_rate'     => (float) $asset->replacement_cost_rate,
            'replacement_years'    => (int) $asset->replacement_cost_interval,
            'terms'                => $asset->payment_terms ?: 'cash',
            'policy'               => $policy,
            'from_total'           => $fromTotal,
            'reservation'          => (float) $asset->reservation_rate,
            'contractual'          => (float) $asset->contractual_rate,
            'after_months'         => (int) $asset->after_months,
            'installment_grace'    => (int) $asset->installment_grace_period,
            'installment_count'    => (int) $asset->installment_count,
            'installment_interval' => $asset->payment_installment_interval ?: 'monthly',
            'equity_rate'          => (float) $asset->equity_funding_rate,
            'interest_rate'        => (float) $asset->interest_rate,
            'tenor'                => (int) $asset->tenor,
            'loan_interval'        => $asset->installment_interval ?: 'monthly',
            'loan_grace'           => (int) $asset->grace_period,
        ];
    }

    /** @return array<int,float> */
    private function percentages(array $typed, array $productIds): array
    {
        $result = [];
        foreach ($productIds as $id) {
            if (isset($typed[$id])) {
                $result[$id] = (float) $typed[$id];
            }
        }

        return $result;
    }
}
