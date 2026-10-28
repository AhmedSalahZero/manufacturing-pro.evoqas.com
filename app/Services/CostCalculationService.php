<?php

namespace App\Services;

use App\Calculations\ExpenseCalculator;
use App\Calculations\ManpowerCalculator;
use App\Calculations\ProductAllocation;
use App\Calculations\Series;
use App\Calculations\Timeline;
use App\Models\Project;
use App\Support\StudyCalendar;
use Illuminate\Support\Facades\DB;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — CostCalculationService (staff cost and expenses of a study)
//  Location: app/Services/CostCalculationService.php
//
//  Reads the staff positions and expense lines of a study, runs the calculation engine (app/Calculations) and saves the
//  monthly results next to the inputs:
//    staff positions:  headcount, salary payments, salary expense (employer cost), tax + social insurance payable
//    products:         the share of direct labour / manufacturing overheads staff cost (product columns)
//    expenses:         monthly amounts, VAT, withholding, payments, payable statement, and the share of each product
//                      (for the manufacturing and other operation categories)
//  It runs at the end of SalesCalculationService::recalculate(), because it needs the sales of the products.
// ══════════════════════════════════════════════════════════════════

class CostCalculationService
{
    /** Staff types. 'column' = the product column that receives the allocated cost; 'indirect' = also listed as indirect manpower. */
    public const MANPOWER_TYPES = [
        'direct_labor'            => ['column' => 'product_manpower_allocation', 'indirect' => false],
        'manufacturing_overheads' => ['column' => 'product_overheads_allocation', 'indirect' => true],
        'operational_salaries'    => ['column' => null, 'indirect' => false],
        'sales'                   => ['column' => null, 'indirect' => false],
        'marketing'               => ['column' => null, 'indirect' => false],
        'general'                 => ['column' => null, 'indirect' => false],
    ];

    /** Expense categories: whether the cost is split between the products. The last two exist for a new company only. */
    public const EXPENSE_CATEGORIES = [
        'manufacturing-expenses'    => true,
        'other-operation-expenses'  => true,
        'marketing'                 => false,
        'sales'                     => false,
        'general-expenses'          => false,
        'pre-operating-expenses'    => false,
        'start-up-expenses'         => false,
    ];

    public const NEW_COMPANY_ONLY = ['pre-operating-expenses', 'start-up-expenses'];

    public const EXPENSE_KINDS = ['fixed_monthly_repeating_amount', 'one_time_expense', 'expense_as_percentage', 'cost_per_unit'];

    /** @return list<string> the categories a study can use */
    public static function categoriesFor(Project $project): array
    {
        return array_values(array_filter(
            array_keys(self::EXPENSE_CATEGORIES),
            fn ($c) => $project->new_company || ! in_array($c, self::NEW_COMPANY_ONLY, true),
        ));
    }

    public function recalculate(Project $project, Timeline $timeline): void
    {
        DB::transaction(function () use ($project, $timeline) {
            $project->load(['products' => fn ($q) => $q->orderBy('id'), 'manpowers', 'expenses']);

            $values = [];
            $units = [];
            $sellingStart = [];
            foreach ($project->products as $product) {
                $values[$product->id] = (array) $product->monthly_sales_target_values;
                $units[$product->id] = (array) $product->monthly_sales_target_quantities;
                $sellingStart[$product->id] = (int) $product->selling_start_date;
            }

            $revenueShares = ProductAllocation::revenueShares($values);
            $months = array_keys($timeline->dates);

            $this->manpower($project, $timeline, $revenueShares, $sellingStart, $months);
            $this->expenses($project, $timeline, $values, $units, $revenueShares, $sellingStart, $months);
        });
    }

    /**
     * Adds up everything that was allocated to each product (indirect manpower, expenses of the manufacturing and other
     * operation categories, depreciation of the assets) into the product's manufacturing overheads, and the depreciation
     * alone into the product's depreciation. The old application did this last, after the fixed assets.
     */
    public function rollUpProductOverheads(Project $project): void
    {
        DB::transaction(function () use ($project) {
            DB::table('products')->where('project_id', $project->id)->update([
                'product_overheads_allocation'  => null,
                'product_depreciation_allocation' => null,
            ]);

            $totals = [];
            $depreciation = [];

            foreach (DB::table('product_expense_allocations')->where('project_id', $project->id)->orderBy('id')->get() as $row) {
                $isDepreciation = $row->is_depreciation || $row->is_opening_depreciation;

                foreach ((array) json_decode((string) $row->payload, true) as $month => $value) {
                    $totals[$row->product_id][$month] = ($totals[$row->product_id][$month] ?? 0) + $value;
                    if ($isDepreciation) {
                        $depreciation[$row->product_id][$month] = ($depreciation[$row->product_id][$month] ?? 0) + $value;
                    }
                }
            }

            foreach ($totals as $productId => $series) {
                DB::table('products')->where('id', $productId)->update([
                    'product_overheads_allocation'    => json_encode($series),
                    'product_depreciation_allocation' => isset($depreciation[$productId]) ? json_encode($depreciation[$productId]) : null,
                ]);
            }
        });
    }

    /** The products a staff type is shared between, with the typed %: what was saved, or all products equally. */
    public function manpowerPercentages(Project $project, string $type): array
    {
        $saved = (array) (($project->manpower_allocations ?? [])[$type] ?? []);
        $ids = $project->products->pluck('id')->all();

        if ($saved !== []) {
            return array_map('floatval', array_intersect_key($saved, array_flip($ids)));
        }

        return $ids === [] ? [] : array_fill_keys($ids, round(100 / count($ids), 4));
    }

    private function manpower(Project $project, Timeline $timeline, array $revenueShares, array $sellingStart, array $months): void
    {
        $costByType = [];

        foreach ($project->manpowers as $row) {
            $result = ManpowerCalculator::calculate(
                $timeline,
                $project->new_company ? 0.0 : (float) $row->existing_count,
                (array) $row->hirings,
                (float) $row->avg_salary,
                (float) $project->salaries_annual_increase,
                (float) $project->salaries_tax_rate,
                (float) $project->social_insurance_rate,
            );
            $row->update($result);
            $costByType[$row->type][] = $result['salary_expenses'];
        }

        $productIds = $project->products->pluck('id')->all();
        $monthly = [];
        $indirect = [];

        foreach (self::MANPOWER_TYPES as $type => $info) {
            if ($info['column'] === null) {
                continue;
            }

            $percent = $this->manpowerPercentages($project, $type);
            $byRevenue = (bool) (($project->manpower_is_as_revenue_percentages ?? [])[$type] ?? true);
            $typed = ProductAllocation::typedShares($percent, $sellingStart, $months);
            $monthly[$type] = $typed;
            $cost = Series::sum($costByType[$type] ?? [], array_keys($timeline->operationMonths));
            $split = ProductAllocation::split($revenueShares, $typed, $productIds, $cost, $byRevenue);

            foreach ($project->products as $product) {
                if (! array_key_exists($product->id, $percent)) {
                    continue;
                }
                $product->update([$info['column'] => $split[$product->id] ?? []]);
                if ($info['indirect']) {
                    $indirect[$type][$product->id] = $split[$product->id] ?? [];
                }
            }
        }

        $project->update(['monthly_manpower_allocations' => $monthly]);

        DB::table('product_expense_allocations')->where('project_id', $project->id)->where('is_indirect_manpower', 1)->delete();
        foreach ($indirect as $type => $perProduct) {
            foreach ($perProduct as $productId => $payload) {
                DB::table('product_expense_allocations')->insert([
                    'project_id' => $project->id, 'product_id' => $productId, 'expense_name' => $type,
                    'is_expense' => 0, 'is_depreciation' => 0, 'is_indirect_manpower' => 1,
                    'payload' => json_encode($payload), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    private function expenses(Project $project, Timeline $timeline, array $values, array $units, array $revenueShares, array $sellingStart, array $months): void
    {
        $studyEnd = ($project->end_date ? StudyCalendar::indexOf($timeline->dates, $project->end_date->format('Y-m-d')) : null) ?? max(array_keys($timeline->operationMonths) ?: [0]);
        $extendedEnd = max(array_keys($timeline->operationMonths) ?: [0]);
        $productIds = $project->products->pluck('id')->all();
        $allocations = [];

        foreach ($project->expenses as $expense) {
            $kind = $expense->relation_name;
            $start = (int) $expense->start_date;
            $end = (int) $expense->end_date >= $studyEnd ? $extendedEnd : (int) $expense->end_date;
            $deductible = (bool) $expense->is_deductible;
            $vatRate = (float) $expense->vat_rate;
            $withholdRate = (float) $expense->withhold_tax_rate;
            $terms = $expense->payment_terms ?: 'cash';
            $policy = array_map('floatval', (array) $expense->custom_collection_policy);
            $byRevenue = (bool) $expense->is_as_revenue_percentages;
            $selected = array_values(array_map('intval', (array) $expense->products));

            $typed = [];
            $perProduct = [];
            $save = [];

            if ($kind === 'fixed_monthly_repeating_amount') {
                $r = ExpenseCalculator::fixedMonthly((float) $expense->amount, $start, $end, (string) ($expense->increase_interval ?: 'annually'), (float) $expense->increase_rate, $deductible, $vatRate, $withholdRate);
                $before = $r['total_before_vat'];
                $vat = $r['total_vat'];
                $after = $r['total_after_vat'];
                $withhold = $r['withhold_amounts'];
                $base = $after;
                $save['monthly_repeating_amounts'] = $before;
            } elseif ($kind === 'one_time_expense') {
                $r = ExpenseCalculator::oneTime((float) $expense->amount, (int) ($expense->amortization_months ?: 12), $start, $deductible, $vatRate);
                $before = [$start => (float) $expense->amount];
                $vat = [$start => (float) $expense->amount * $vatRate / 100];
                $after = [$start => (float) $expense->amount * (1 + $vatRate / 100)];
                $withhold = [$start => (float) $expense->amount * $withholdRate / 100];
                $base = $r['monthly_one_time'];
                $save['payload'] = $r;
            } else {
                $series = $kind === 'expense_as_percentage'
                    ? ExpenseCalculator::percentageOfRevenue(array_intersect_key($values, array_flip($selected)), $start, $end, (float) $expense->monthly_percentage)
                    : ExpenseCalculator::costPerUnit(array_intersect_key($units, array_flip($selected)), $start, $end, (float) $expense->monthly_cost_of_unit);
                $before = $series['expense_amounts'];
                $vat = [];
                $after = $before;
                $withhold = [];
                $perProduct = $series['expense_allocations'];
                $base = null;
                $save['expense_as_percentages'] = $before;
            }

            if ($base !== null && (self::EXPENSE_CATEGORIES[$expense->category_id] ?? false)) {
                $percent = array_map('floatval', (array) $expense->product_allocations);
                $typed = ProductAllocation::typedShares($percent, $sellingStart, $months);
                $perProduct = ProductAllocation::split($revenueShares, $typed, $productIds, $base, $byRevenue);
            }

            $payments = ExpenseCalculator::payments($terms, $policy, $after, $timeline);
            $withholdPayments = ExpenseCalculator::payments($terms, $policy, $withhold, $timeline);

            $expense->update($save + [
                'total_vat'                   => $vat,
                'total_after_vat'             => $after,
                'withhold_amounts'            => $withhold,
                'payment_amounts'             => $payments,
                'withhold_payments'           => $withholdPayments,
                'net_payments_after_withhold' => Series::subtract($payments, [$withholdPayments], array_keys($payments)),
                'collection_statements'       => ExpenseCalculator::statement($before, $vat, $payments, $withholdPayments, $timeline),
                'monthly_product_allocations' => $typed,
            ]);

            if ($expense->name !== '' && (self::EXPENSE_CATEGORIES[$expense->category_id] ?? false)) {
                foreach ($perProduct as $productId => $payload) {
                    $allocations[] = [
                        'project_id' => $project->id, 'product_id' => $productId, 'expense_type' => $kind,
                        'expense_category' => $expense->category_id, 'expense_name' => $expense->name,
                        'is_expense' => 1, 'is_depreciation' => 0, 'payload' => json_encode($payload),
                        'created_at' => now(), 'updated_at' => now(),
                    ];
                }
            }
        }

        DB::table('product_expense_allocations')->where('project_id', $project->id)->where('is_expense', 1)->delete();
        if ($allocations !== []) {
            DB::table('product_expense_allocations')->insert($allocations);
        }
    }
}
