<?php

namespace App\Services;

use App\Calculations\OpeningBalanceCalculator;
use App\Calculations\ProductAllocation;
use App\Calculations\Timeline;
use App\Models\CashAndBankOpeningBalance;
use App\Models\EquityOpeningBalance;
use App\Models\FixedAssetOpeningBalance;
use App\Models\LongTermLoanOpeningBalance;
use App\Models\OtherCreditsOpeningBalance;
use App\Models\OtherDebtorsOpeningBalance;
use App\Models\OtherLongTermLiabilitiesOpeningBalance;
use App\Models\Project;
use App\Models\SupplierPayableOpeningBalance;
use App\Models\VatAndCreditWithholdTaxOpeningBalance;
use App\Support\StudyCalendar;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — OpeningBalanceService (what an existing company already has on day one)
//  Location: app/Services/OpeningBalanceService.php
//
//  save()         saves the opening balances form, then recalculates the whole study (SalesCalculationService).
//                 Rows sent with an id are updated, rows without one are created, rows no longer sent are deleted.
//                 The single-row sections (cash and banks, suppliers, taxes, equity) always keep exactly one row.
//  recalculate()  works out, for every balance that is collected or paid over time, the monthly statement (what is left each
//                 month); the depreciation of the assets already owned (and its share in each product's cost); and the
//                 paid-up capital and legal reserve for every month. The statements are read by the financial statements.
//  totals()       the figures the "do the balances balance?" check adds up.
//  Only existing companies have opening balances; a new company starts from zero.
// ══════════════════════════════════════════════════════════════════

class OpeningBalanceService
{
    public function __construct(private readonly SalesCalculationService $calculations)
    {
    }

    /** @param array<string,mixed> $data already validated (see UpdateOpeningBalancesRequest) */
    public function save(Project $project, array $data): void
    {
        DB::transaction(function () use ($project, $data) {
            $productIds = $project->products()->pluck('id')->all();

            $this->syncMany($project, FixedAssetOpeningBalance::class, $data['fixed_assets'] ?? [], function (array $r) use ($productIds) {
                $admin = (float) ($r['admin_depreciation_percentage'] ?? 0);
                $byRevenue = (bool) ($r['is_as_revenue_percentages'] ?? true);

                return [
                    'name'                                  => $r['name'],
                    'gross_amount'                          => $r['gross_amount'] ?? 0,
                    'accumulated_depreciation'              => $r['accumulated_depreciation'] ?? 0,
                    'monthly_depreciation'                  => $r['monthly_depreciation'] ?? 0,
                    'monthly_counts'                        => (int) ($r['monthly_counts'] ?? 0),
                    'admin_depreciation_percentage'         => $admin,
                    'manufacturing_depreciation_percentage' => 100 - $admin,
                    'is_as_revenue_percentages'             => $byRevenue ? 1 : 0,
                    'product_allocations'                    => $byRevenue ? [] : $this->percentages($r['allocations'] ?? [], $productIds),
                ];
            });

            $this->syncOne($project, CashAndBankOpeningBalance::class, $data['cash'] ?? [], fn (array $r) => [
                'cash_and_bank_amount'       => $r['cash_and_bank_amount'] ?? 0,
                'customer_receivable_amount' => $r['customer_receivable_amount'] ?? 0,
                'payload'                    => $this->series($r['settlements'] ?? []),
            ]);

            $this->syncMany($project, OtherDebtorsOpeningBalance::class, $data['other_debtors'] ?? [], fn (array $r) => [
                'name' => $r['name'] ?? null, 'amount' => $r['amount'] ?? 0, 'payload' => $this->series($r['settlements'] ?? []),
            ]);

            $this->syncOne($project, SupplierPayableOpeningBalance::class, $data['suppliers'] ?? [], fn (array $r) => [
                'amount' => $r['amount'] ?? 0, 'payload' => $this->series($r['settlements'] ?? []),
            ]);

            $this->syncMany($project, OtherCreditsOpeningBalance::class, $data['other_creditors'] ?? [], fn (array $r) => [
                'name' => $r['name'] ?? null, 'amount' => $r['amount'] ?? 0, 'payload' => $this->series($r['settlements'] ?? []),
            ]);

            $this->syncOne($project, VatAndCreditWithholdTaxOpeningBalance::class, $data['taxes'] ?? [], fn (array $r) => [
                'vat_amount'              => $r['vat_amount'] ?? 0,
                'credit_withhold_taxes'   => $r['credit_withhold_taxes'] ?? 0,
                'corporate_taxes_payable' => $r['corporate_taxes_payable'] ?? 0,
            ]);

            $this->syncMany($project, LongTermLoanOpeningBalance::class, $data['loans'] ?? [], fn (array $r) => [
                'amount'       => $r['amount'] ?? 0,
                'installments' => $this->series($r['installments'] ?? []),
                'interests'    => $this->series($r['interests'] ?? []),
            ]);

            $this->syncMany($project, OtherLongTermLiabilitiesOpeningBalance::class, $data['other_long_term'] ?? [], fn (array $r) => [
                'amount' => $r['amount'] ?? 0, 'payload' => $this->series($r['settlements'] ?? []),
            ]);

            $this->syncOne($project, EquityOpeningBalance::class, $data['equity'] ?? [], fn (array $r) => [
                'paid_up_capital_amount' => $r['paid_up_capital_amount'] ?? 0,
                'legal_reserve'          => $r['legal_reserve'] ?? 0,
                'retained_earnings'      => $r['retained_earnings'] ?? 0,
            ]);
        });

        $this->calculations->recalculate($project->refresh());
    }

    /** Calculates every statement and saves it next to the inputs. */
    public function recalculate(Project $project, Timeline $timeline): void
    {
        DB::transaction(function () use ($project, $timeline) {
            DB::table('product_expense_allocations')->where('project_id', $project->id)->where('is_opening_depreciation', 1)->delete();

            if ($project->new_company) {
                return;
            }

            $studyEnd = $this->studyEnd($project, $timeline);
            $months = range(0, $studyEnd);

            foreach (OtherDebtorsOpeningBalance::where('project_id', $project->id)->get() as $row) {
                $row->update(['statement' => OpeningBalanceCalculator::settlementStatement($months, (array) $row->payload, (float) $row->amount, $timeline)]);
            }
            foreach (OtherCreditsOpeningBalance::where('project_id', $project->id)->get() as $row) {
                $row->update(['statement' => OpeningBalanceCalculator::settlementStatement($months, (array) $row->payload, (float) $row->amount, $timeline)]);
            }
            foreach (SupplierPayableOpeningBalance::where('project_id', $project->id)->get() as $row) {
                $row->update(['statement' => OpeningBalanceCalculator::settlementStatement($months, (array) $row->payload, (float) $row->amount, $timeline)]);
            }
            foreach (OtherLongTermLiabilitiesOpeningBalance::where('project_id', $project->id)->get() as $row) {
                $row->update(['statement' => OpeningBalanceCalculator::settlementStatement($months, (array) $row->payload, (float) $row->amount, $timeline)]);
            }
            foreach (LongTermLoanOpeningBalance::where('project_id', $project->id)->get() as $row) {
                $row->update(['statement' => OpeningBalanceCalculator::settlementStatement($months, (array) $row->installments, (float) $row->amount, $timeline)]);
            }
            foreach (CashAndBankOpeningBalance::where('project_id', $project->id)->get() as $row) {
                $row->update(['statement' => OpeningBalanceCalculator::settlementStatement($months, (array) $row->payload, (float) $row->customer_receivable_amount, $timeline)]);
            }

            $operationMonths = array_values(array_filter(array_keys($timeline->operationMonths), fn ($m) => $m <= $studyEnd));
            foreach (EquityOpeningBalance::where('project_id', $project->id)->get() as $row) {
                $row->update([
                    'paid_up_capital_extended' => OpeningBalanceCalculator::repeated((float) $row->paid_up_capital_amount, $operationMonths),
                    'legal_reserve_extended'   => OpeningBalanceCalculator::repeated((float) $row->legal_reserve, $operationMonths),
                ]);
            }

            $this->fixedAssets($project, $timeline, $months);
        });
    }

    /**
     * The figures behind the "do the balances balance?" check.
     *
     * @return array<string,float>
     */
    public function totals(Project $project): array
    {
        $sum = fn (string $relation, string $column) => (float) $project->{$relation}->sum($column);
        $project->loadMissing([
            'fixedAssetOpeningBalances', 'cashAndBankOpeningBalances', 'otherDebtorsOpeningBalances', 'supplierPayableOpeningBalances',
            'otherCreditsOpeningBalances', 'vatAndCreditWithholdTaxOpeningBalances', 'longTermLoanOpeningBalances',
            'otherLongTermLiabilitiesOpeningBalances', 'equityOpeningBalances', 'products', 'rawMaterials',
        ]);

        return [
            'net_fixed_assets'  => $sum('fixedAssetOpeningBalances', 'gross_amount') - $sum('fixedAssetOpeningBalances', 'accumulated_depreciation'),
            'cash'              => $sum('cashAndBankOpeningBalances', 'cash_and_bank_amount'),
            'receivables'       => $sum('cashAndBankOpeningBalances', 'customer_receivable_amount'),
            'inventory'         => $this->inventory($project),
            'other_debtors'     => $sum('otherDebtorsOpeningBalances', 'amount'),
            'suppliers'         => $sum('supplierPayableOpeningBalances', 'amount'),
            'other_creditors'   => $sum('otherCreditsOpeningBalances', 'amount'),
            'vat'               => $sum('vatAndCreditWithholdTaxOpeningBalances', 'vat_amount'),
            'withhold'          => $sum('vatAndCreditWithholdTaxOpeningBalances', 'credit_withhold_taxes'),
            'corporate_tax'     => $sum('vatAndCreditWithholdTaxOpeningBalances', 'corporate_taxes_payable'),
            'loans'             => $sum('longTermLoanOpeningBalances', 'amount'),
            'other_long_term'   => $sum('otherLongTermLiabilitiesOpeningBalances', 'amount'),
            'paid_up'           => $sum('equityOpeningBalances', 'paid_up_capital_amount'),
            'legal_reserve'     => $sum('equityOpeningBalances', 'legal_reserve'),
            'retained_earnings' => $sum('equityOpeningBalances', 'retained_earnings'),
        ];
    }

    /** Finished goods and raw materials on the first day (typed on the product and raw material forms). */
    public function inventory(Project $project): float
    {
        return (float) $project->products()->sum('fg_inventory_value') + (float) $project->rawMaterials()->sum('beginning_inventory_value');
    }

    /** The study's last month position (the study end, not the extra year the calculations also cover). */
    public function studyEnd(Project $project, Timeline $timeline): int
    {
        $months = array_keys($timeline->dates);

        return ($project->end_date ? StudyCalendar::indexOf($timeline->dates, $project->end_date->format('Y-m-d')) : null) ?? max($months);
    }

    /** Depreciation of the assets already owned, and its share in each product's manufacturing cost. */
    private function fixedAssets(Project $project, Timeline $timeline, array $months): void
    {
        $project->load(['products' => fn ($q) => $q->orderBy('id')]);

        $values = [];
        $sellingStart = [];
        foreach ($project->products as $product) {
            $values[$product->id] = (array) $product->monthly_sales_target_values;
            $sellingStart[$product->id] = (int) $product->selling_start_date;
        }
        $revenueShares = ProductAllocation::revenueShares($values);
        $productIds = $project->products->pluck('id')->all();
        $rows = [];

        foreach (FixedAssetOpeningBalance::where('project_id', $project->id)->orderBy('id')->get() as $asset) {
            $result = OpeningBalanceCalculator::fixedAsset([
                'gross'             => (float) $asset->gross_amount,
                'accumulated'       => (float) $asset->accumulated_depreciation,
                'monthly'           => (float) $asset->monthly_depreciation,
                'months'            => (int) $asset->monthly_counts,
                'admin_pct'         => (float) $asset->admin_depreciation_percentage,
                'manufacturing_pct' => (float) $asset->manufacturing_depreciation_percentage,
            ], $timeline, $months);

            $byRevenue = (bool) $asset->is_as_revenue_percentages;
            $typed = ProductAllocation::typedShares(array_map('floatval', (array) $asset->product_allocations), $sellingStart, array_keys($timeline->dates));

            $asset->update([
                'admin_depreciations'               => $result['admin'],
                'manufacturing_depreciations'       => $result['manufacturing'],
                'monthly_accumulated_depreciations' => $result['accumulated'],
                'statement'                         => $result['statement'],
                'monthly_product_allocations'       => $byRevenue ? [] : $typed,
            ]);

            if ($result['manufacturing'] !== [] && $productIds !== []) {
                foreach (ProductAllocation::split($revenueShares, $typed, $productIds, $result['manufacturing'], $byRevenue) as $productId => $payload) {
                    $rows[] = [
                        'project_id' => $project->id, 'product_id' => $productId, 'expense_name' => $asset->name,
                        'is_expense' => 0, 'is_depreciation' => 0, 'is_opening_depreciation' => 1, 'payload' => json_encode($payload),
                        'created_at' => now(), 'updated_at' => now(),
                    ];
                }
            }
        }

        if ($rows !== []) {
            DB::table('product_expense_allocations')->insert($rows);
        }
    }

    /**
     * @param  class-string<Model>  $model
     * @param  callable(array):array  $values
     */
    private function syncMany(Project $project, string $model, array $rows, callable $values): void
    {
        $kept = [];

        foreach ($rows as $row) {
            $existing = ! empty($row['id']) ? $model::where('project_id', $project->id)->find($row['id']) : null;

            if ($existing) {
                $existing->update($values($row));
                $kept[] = $existing->id;
            } else {
                $kept[] = $model::create($values($row) + ['project_id' => $project->id])->id;
            }
        }

        $model::where('project_id', $project->id)->whereNotIn('id', $kept)->delete();
    }

    /**
     * @param  class-string<Model>  $model
     * @param  callable(array):array  $values
     */
    private function syncOne(Project $project, string $model, array $row, callable $values): void
    {
        $first = $model::where('project_id', $project->id)->orderBy('id')->first();

        if ($first) {
            $first->update($values($row));
            $model::where('project_id', $project->id)->where('id', '!=', $first->id)->delete();
        } else {
            $model::create($values($row) + ['project_id' => $project->id]);
        }
    }

    /**
     * Month position => amount, only what was typed.
     *
     * @return array<int,float>
     */
    private function series(array $typed): array
    {
        $result = [];
        foreach ($typed as $month => $value) {
            if ($value !== null && $value !== '' && (float) $value != 0.0) {
                $result[(int) $month] = (float) $value;
            }
        }
        ksort($result);

        return $result;
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
