<?php

namespace App\Services;

use App\Calculations\FgCostingCalculator;
use App\Calculations\FinancialStatementsCalculator as F;
use App\Calculations\TaxCalculator;
use App\Calculations\Timeline;
use App\Models\BalanceSheet;
use App\Models\CashAndBankOpeningBalance;
use App\Models\CashInOutStatement;
use App\Models\EquityOpeningBalance;
use App\Models\Expense;
use App\Models\FixedAsset;
use App\Models\FixedAssetOpeningBalance;
use App\Models\IncomeStatement;
use App\Models\LoanSchedulePayment;
use App\Models\LongTermLoanOpeningBalance;
use App\Models\Manpower;
use App\Models\OtherCreditsOpeningBalance;
use App\Models\OtherDebtorsOpeningBalance;
use App\Models\OtherLongTermLiabilitiesOpeningBalance;
use App\Models\Product;
use App\Models\Project;
use App\Models\RawMaterial;
use App\Models\SupplierPayableOpeningBalance;
use App\Models\VatAndCreditWithholdTaxOpeningBalance;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — StatementsService (income statement, cash flow, balance sheet, VAT and tax)
//  Location: app/Services/StatementsService.php
//
//  Runs LAST in SalesCalculationService::recalculate(), because it adds up everything the earlier steps saved:
//    1. costFinishedGoods   cost of goods sold and stock value of every product (FgCostingCalculator)
//    2. build               VAT, corporate tax, income statement, cash flow and balance sheet (pure engines in app/Calculations)
//    3. persist             saves them (income_statements, cash_in_out_statements, balance_sheets, projects.vat_statements /
//                           corporate_taxes_statement)
//  present() turns the built statements into rows for the screen (yearly figures worked out here, months kept for the pop-out).
//  The tax rules are the Egyptian ones documented in app/Calculations/TaxCalculator.php.
// ══════════════════════════════════════════════════════════════════

class StatementsService
{
    /** Corporate tax is worked out on the profit of the whole calendar year (the old application: month by month). */
    public const TAX_BASIS = TaxCalculator::BASIS_YEAR;

    /** Expense categories that are costs of the period (the others are shared into the cost of the products). */
    private const PERIOD_CATEGORIES = ['general-expenses', 'sales', 'marketing'];

    private const NEW_COMPANY_CATEGORIES = ['start-up-expenses', 'pre-operating-expenses'];

    /** Staff types that are costs of the period → the expense category they are shown under. */
    private const PERIOD_STAFF = ['sales' => 'sales', 'marketing' => 'marketing', 'general' => 'general-expenses', 'operational_salaries' => 'general-expenses'];

    public function recalculate(Project $project, Timeline $timeline): void
    {
        $this->costFinishedGoods($project, $timeline);
        $this->persist($project, $this->build($project, $timeline));
    }

    // ── 1. Cost of goods sold ───────────────────────────────────────────────

    private function costFinishedGoods(Project $project, Timeline $timeline): void
    {
        foreach (Product::where('project_id', $project->id)->orderBy('id')->get() as $product) {
            $quantities = (array) $product->product_inventory_qt_statement;
            $available = (array) ($quantities['total_quantity_available'] ?? []);
            $sold = (array) ($quantities['sales_quantity'] ?? []);

            $breakdown = (array) $product->fg_beginning_inventory_breakdowns;
            $opening = [];
            foreach (FgCostingCalculator::TYPES as $type) {
                $opening[$type] = $project->new_company ? 0.0 : (float) ($breakdown[$type]['value'] ?? 0);
            }

            $months = array_keys($available);
            $pad = fn (array $series) => $this->padded($series, $months);

            $result = FgCostingCalculator::calculate($available, $sold, $opening, [
                FgCostingCalculator::RAW_MATERIAL  => $pad((array) (((array) $product->product_raw_material_consumed)['total'] ?? [])),
                FgCostingCalculator::DIRECT_LABOR  => $pad((array) $product->product_manpower_allocation),
                FgCostingCalculator::OVERHEADS     => $pad((array) $product->product_overheads_allocation),
                FgCostingCalculator::DEPRECIATION  => $pad((array) $product->product_depreciation_allocation),
            ]);

            $product->update([
                'raw_material_statement'           => $result['statements'][FgCostingCalculator::RAW_MATERIAL],
                'product_manpower_statement'       => $result['statements'][FgCostingCalculator::DIRECT_LABOR],
                'product_overheads_statement'      => $result['statements'][FgCostingCalculator::OVERHEADS],
                'product_depreciation_statement'   => $result['statements'][FgCostingCalculator::DEPRECIATION],
                'product_inventory_value_statement' => $result['inventory'],
            ]);
        }
    }

    // ── 2. The statements ───────────────────────────────────────────────────

    /**
     * @return array<string,mixed>  months, years, vat, corporate_tax, income, cash, summary, balance, lines, working_capital, funding
     */
    public function build(Project $project, Timeline $timeline): array
    {
        $studyMonths = (int) $project->duration * 12;
        $months = range(0, $studyMonths - 1);
        $allMonths = array_keys($timeline->dates);
        $new = (bool) $project->new_company;
        $id = $project->id;

        $products = Product::where('project_id', $id)->orderBy('id')->get();
        $materials = RawMaterial::where('project_id', $id)->orderBy('id')->get();
        $expenses = Expense::where('project_id', $id)->orderBy('id')->get();
        $staff = Manpower::where('project_id', $id)->orderBy('id')->get();
        $assets = FixedAsset::where('project_id', $id)->orderBy('id')->get();
        $loans = LoanSchedulePayment::where('project_id', $id)->orderBy('id')->get();

        $opening = fn (string $model) => $new ? collect() : $model::where('project_id', $id)->orderBy('id')->get();
        $openAssets = $opening(FixedAssetOpeningBalance::class);
        $openCash = $opening(CashAndBankOpeningBalance::class)->first();
        $openDebtors = $opening(OtherDebtorsOpeningBalance::class);
        $openSupplier = $opening(SupplierPayableOpeningBalance::class)->first();
        $openCreditors = $opening(OtherCreditsOpeningBalance::class);
        $openTaxes = $opening(VatAndCreditWithholdTaxOpeningBalance::class)->first();
        $openLoans = $opening(LongTermLoanOpeningBalance::class);
        $openLongTerm = $opening(OtherLongTermLiabilitiesOpeningBalance::class);
        $openEquity = $opening(EquityOpeningBalance::class)->first();

        $sum = fn (array $list) => F::add($list, $months);
        $at = fn ($model, string ...$path) => $this->at($model, $path);

        // Calendar years of the study; the tax of a year is booked in its last month and paid in April of the next year.
        $years = [];
        foreach ($months as $m) {
            $years[$timeline->yearIndexOf($m)][] = $m;
        }
        $yearEnds = array_map(fn ($ms) => end($ms), $years);
        $paidIn = [];
        foreach ($yearEnds as $month) {
            $april = TaxCalculator::aprilOfNextYear($timeline, $month);
            if ($april !== null) {
                $paidIn[$month] = $april;
            }
        }

        // ── Income statement ──
        $revenue = [];
        $cogs = ['raw_material' => [], 'direct_labor' => [], 'manufacturing_overheads' => []];
        $cogsDepreciation = [];
        foreach ($products as $product) {
            $revenue[$product->id] = (array) $product->monthly_sales_target_values;
            $cogs['raw_material'][] = $at($product, 'raw_material_statement', 'cogs');
            $cogs['direct_labor'][] = $at($product, 'product_manpower_statement', 'cogs');
            $cogs['manufacturing_overheads'][] = $at($product, 'product_overheads_statement', 'cogs');
            $cogsDepreciation[] = $at($product, 'product_depreciation_statement', 'cogs');
        }
        foreach ($cogs as $key => $list) {
            $cogs[$key] = $sum($list);
        }

        $categories = $new ? array_merge(self::PERIOD_CATEGORIES, self::NEW_COMPANY_CATEGORIES) : self::PERIOD_CATEGORIES;
        $byCategory = [];
        foreach ($expenses as $expense) {
            if (! in_array($expense->category_id, $categories, true)) {
                continue;
            }
            $byCategory[$expense->category_id][] = match ($expense->relation_name) {
                'one_time_expense'               => (array) ($this->jd($expense->payload)['monthly_one_time'] ?? []),
                'fixed_monthly_repeating_amount' => $this->jd($expense->monthly_repeating_amounts),
                default                          => $this->jd($expense->expense_as_percentages),
            };
        }
        foreach ($staff as $person) {
            if (isset(self::PERIOD_STAFF[$person->type])) {
                $byCategory[self::PERIOD_STAFF[$person->type]][] = $this->jd($person->salary_expenses);
            }
        }
        $adminDepreciation = [];
        foreach ($assets as $asset) {
            $adminDepreciation[] = $this->jd($asset->admin_depreciations);
        }
        foreach ($openAssets as $asset) {
            $adminDepreciation[] = $this->jd($asset->admin_depreciations);
        }
        if ($adminDepreciation !== []) {
            $byCategory['general-expenses'][] = $sum($adminDepreciation);
        }
        $expenseLines = [];
        foreach ($byCategory as $category => $list) {
            $expenseLines[$category] = $sum($list);
        }

        $assetsById = $assets->keyBy('id');
        $finance = [];
        foreach ($loans as $loan) {
            $asset = $assetsById->get($loan->fixed_asset_id);
            $capitalised = $this->jd($asset?->income_statement_loan_capitalized_interests);

            // Interest up to the month the asset is transferred is added to the cost of the asset (projects under progress);
            // only the interest after that is an expense. (The old application also expensed the capitalised part: counted twice.)
            $built = $this->at($asset, ['capitalization_statement', 'end_balance']);
            $transferred = $built === [] ? null : array_key_last($built);
            $interest = array_filter($this->jd($loan->interestAmount), fn ($value, $month) => $transferred === null || $month >= $transferred, ARRAY_FILTER_USE_BOTH);

            $finance['loan'.$loan->id] = $sum([$capitalised, $interest]);
        }
        foreach ($openLoans as $row) {
            $finance['opening'.$row->id] = $sum([$this->jd($row->interests)]);
        }

        $income = F::incomeStatement([
            'months'                    => $months,
            'revenue'                   => $revenue,
            'cogs'                      => $cogs,
            'expenses'                  => $expenseLines,
            'cogs_depreciation'         => $sum($cogsDepreciation),
            'admin_depreciation'        => $sum($adminDepreciation),
            'finance'                   => $finance,
            'tax_rate'                  => (float) $project->tax_rate,
            'tax_basis'                 => self::TAX_BASIS,
            'years'                     => $years,
            'tax_booked_at'             => $yearEnds,
            'opening_retained_earnings' => (float) ($openEquity?->retained_earnings ?? 0),
        ]);

        // ── VAT and corporate tax ──
        $salesVat = [];
        $debitWithhold = [];
        foreach ($products as $product) {
            foreach ((array) $at($product, 'collection_statement', 'monthly', 'vat') as $m => $v) {
                $salesVat[$m] = ($salesVat[$m] ?? 0) + $v;
            }
            foreach ((array) $at($product, 'collection_statement', 'monthly', 'withhold_amount') as $m => $v) {
                $debitWithhold[$m] = ($debitWithhold[$m] ?? 0) + $v;
            }
        }
        $purchaseVat = [];
        foreach ($materials as $material) {
            foreach ((array) $at($material, 'collection_statement', 'monthly', 'vat') as $m => $v) {
                $purchaseVat[$m] = ($purchaseVat[$m] ?? 0) + $v;
            }
        }
        $additions = [];
        foreach ($allMonths as $m) {
            $additions[$m] = ($salesVat[$m] ?? 0) - ($purchaseVat[$m] ?? 0);
        }
        $vat = TaxCalculator::vatStatement($additions, (float) ($openTaxes?->vat_amount ?? 0), $allMonths);

        $openingCorporateTax = (float) ($openTaxes?->corporate_taxes_payable ?? 0);
        // A study that starts in January to April pays the opening tax in the first April; otherwise it is paid with the first year's tax.
        $firstApril = TaxCalculator::firstAprilOfFirstYear($timeline);
        $corporateTax = TaxCalculator::corporateTaxStatement($debitWithhold, $income['tax'], $openingCorporateTax, $months, $paidIn, $firstApril);

        // ── Cash flow ──
        $collections = [];
        foreach ($products as $product) {
            $collections[] = $at($product, 'collection_statement', 'monthly', 'payment');
        }
        $debtorSettlements = $sum($openDebtors->map(fn ($r) => $this->jd($r->payload))->all());
        $equityPayments = $sum($assets->map(fn ($a) => $this->jd($a->ffe_equity_payment))->all());
        $loanWithdrawals = $sum($assets->map(fn ($a) => $this->jd($a->ffe_loan_withdrawal))->all());
        $cashIn = [
            'collections'         => $sum($collections),
            'opening_receivables' => $openCash ? $this->jd($openCash->payload) : [],
            'opening_debtors'     => $debtorSettlements,
            'equity'              => $equityPayments,
            'loans'               => $loanWithdrawals,
        ];

        $supplierPayments = [];
        $creditWithholdPaid = [];
        foreach ($materials as $material) {
            $supplierPayments[] = $at($material, 'collection_statement', 'monthly', 'payment');
            $creditWithholdPaid[] = $at($material, 'credit_withhold_statement', 'monthly', 'payment');
        }
        $creditWithholdPaid = $sum($creditWithholdPaid);
        $openingCreditWithhold = (float) ($openTaxes?->credit_withhold_taxes ?? 0);
        if ($openingCreditWithhold > 0) {
            $creditWithholdPaid[0] = ($creditWithholdPaid[0] ?? 0) + $openingCreditWithhold;
        }
        $cashOut = [
            'raw_materials'       => $sum($supplierPayments),
            'opening_suppliers'   => $openSupplier ? $this->jd($openSupplier->payload) : [],
            'opening_creditors'   => $sum($openCreditors->map(fn ($r) => $this->jd($r->payload))->all()),
            'salaries'            => $sum($staff->map(fn ($p) => $this->jd($p->salary_payments))->all()),
            'salary_taxes'        => $sum($staff->map(fn ($p) => $at($p, 'tax_and_social_insurance_statement', 'monthly', 'payment'))->all()),
            'credit_withhold'     => $creditWithholdPaid,
            'vat'                 => (array) $vat['payment'],
            'corporate_tax'       => (array) $corporateTax['payment'],
            'expenses'            => $sum($expenses->map(fn ($e) => $this->jd($e->payment_amounts))->all()),
            'fixed_assets'        => $sum($assets->map(fn ($a) => $this->jd($a->ffe_payment))->all()),
            'replacement'         => $sum($assets->map(fn ($a) => $at($a, 'statement', 'replacement_cost'))->all()),
            'loan_schedules'      => $sum($loans->map(fn ($l) => $this->jd($l->schedulePayment))->all()),
            'opening_loans'       => $sum($openLoans->flatMap(fn ($r) => [$this->jd($r->installments), $this->jd($r->interests)])->all()),
            'opening_long_term'   => $sum($openLongTerm->map(fn ($r) => $this->jd($r->payload))->all()),
        ];
        $cash = F::cashFlow([
            'months'       => $months,
            'opening_cash' => (float) ($openCash?->cash_and_bank_amount ?? 0),
            'cash_in'      => $cashIn,
            'cash_out'     => $cashOut,
        ]);

        $summary = [
            'customer_collection'       => $sum([$cashIn['collections'], $cashIn['opening_receivables'], $cashIn['opening_debtors']]),
            'equity_injection'          => $cashIn['equity'],
            'loan_withdrawal'           => $cashIn['loans'],
            'supplier_payments'         => $sum([$cashOut['raw_materials'], $cashOut['opening_suppliers'], $cashOut['opening_creditors']]),
            'expenses'                  => $sum([$cashOut['salaries'], $cashOut['expenses']]),
            'taxes'                     => $sum([$cashOut['salary_taxes'], $cashOut['credit_withhold'], $cashOut['vat'], $cashOut['corporate_tax']]),
            'fixed_asset_payments'      => $sum([$cashOut['fixed_assets'], $cashOut['replacement']]),
            'loan_installments'         => $sum([$cashOut['loan_schedules'], $cashOut['opening_loans'], $cashOut['opening_long_term']]),
            'total_cash_out'            => $cash['cash_out'],
            'working_capital_injection' => $cash['injection'],
            'cash_end_balance'          => $cash['end_balance'],
        ];

        // ── Balance sheet ──
        $levels = fn ($rows, string ...$path) => $sum($rows->map(fn ($r) => $at($r, ...$path))->all());
        $loanBalances = [];
        foreach ($assets as $asset) {
            $drawn = $this->jd($asset->ffe_loan_withdrawal_end_balance);
            array_pop($drawn);    // the last drawdown month is the loan's first repayment-schedule month
            $loanBalances[] = $drawn;
        }

        $lines = [
            'net_fixed_assets'          => $sum([$levels($openAssets, 'statement', 'monthly', 'end_balance'), $levels($assets, 'statement', 'end_balance')]),
            'projects_under_progress'   => $levels($assets, 'capitalization_statement', 'end_balance'),
            'cash'                      => $cash['end_balance'],
            'customer_receivables'      => $sum([$levels($opening(CashAndBankOpeningBalance::class), 'statement', 'monthly', 'end_balance'), $levels($products, 'collection_statement', 'monthly', 'end_balance')]),
            'fg_inventory'              => $sum($products->map(fn ($p) => $at($p, 'product_inventory_value_statement', 'end_balance'))->all()),
            'rm_inventory'              => $levels($materials, 'inventory_value_statement', 'end_balance'),
            'other_debtors'             => $sum([$levels($openDebtors, 'statement', 'monthly', 'end_balance'), $sum($expenses->where('relation_name', 'one_time_expense')->map(fn ($e) => (array) ($this->jd($e->payload)['end_balance'] ?? []))->all())]),
            'supplier_payables'         => $sum([$levels($opening(SupplierPayableOpeningBalance::class), 'statement', 'monthly', 'end_balance'), $levels($materials, 'collection_statement', 'monthly', 'end_balance')]),
            'fixed_asset_creditors'     => $sum($assets->map(fn ($a) => $this->jd($a->ffe_payable))->all()),
            'vat'                       => (array) $vat['end_balance'],
            'credit_withhold'           => $levels($materials, 'credit_withhold_statement', 'monthly', 'end_balance'),
            'salary_taxes'              => $levels($staff, 'tax_and_social_insurance_statement', 'monthly', 'end_balance'),
            'corporate_tax'             => (array) $corporateTax['end_balance'],
            'other_creditors'           => $sum([$levels($expenses, 'collection_statements', 'monthly', 'end_balance'), $levels($openCreditors, 'statement', 'monthly', 'end_balance')]),
            'loans'                     => $sum(array_merge([$levels($openLoans, 'statement', 'monthly', 'end_balance'), $sum($loans->map(fn ($l) => $this->jd($l->endBalance))->all())], $loanBalances)),
            'other_long_term'           => $levels($openLongTerm, 'statement', 'monthly', 'end_balance'),
            'paid_up_capital'           => array_fill_keys($months, (float) ($openEquity?->paid_up_capital_amount ?? 0)),
            'additional_paid_up_capital' => F::accumulate(F::add([$cash['injection'], $cashIn['equity']], $months), $months),
            'legal_reserve'             => array_fill_keys($months, (float) ($openEquity?->legal_reserve ?? 0)),
            'retained_earnings'         => $income['retained_earnings'],
            'profit_of_period'          => $income['net_profit'],
        ];
        $balance = F::balanceSheet(['months' => $months] + $lines);

        // ── Working capital and funding (per year) ──
        $assetOpening = [
            'customer_receivables' => (float) ($openCash?->customer_receivable_amount ?? 0),
            'fg_inventory'         => $new ? 0.0 : (float) $products->sum('fg_inventory_value'),
            'rm_inventory'         => $new ? 0.0 : (float) $materials->sum('beginning_inventory_value'),
            'other_debtors'        => (float) $openDebtors->sum('amount'),
        ];
        $liabilityOpening = [
            'supplier_payables' => (float) ($openSupplier?->amount ?? 0),
            'other_creditors'   => (float) $openCreditors->sum('amount'),
            'other_long_term'   => (float) $openLongTerm->sum('amount'),
        ];
        $change = [];
        foreach ($assetOpening as $key => $value) {
            $change[$key] = F::yearlyChange($lines[$key], $value, $yearEnds, true);
        }
        foreach ($liabilityOpening as $key => $value) {
            $change[$key] = F::yearlyChange($lines[$key], $value, $yearEnds, false);
        }
        $net = F::add(array_values($change), array_keys($years));

        $equityYear = [];
        $loanYear = [];
        foreach ($yearEnds as $year => $last) {
            $equityYear[$year] = $balance['total_equity'][$last] ?? 0;
            $loanYear[$year] = $lines['loans'][$last] ?? 0;
        }
        $capital = F::add([$equityYear, $loanYear], array_keys($years));
        $funding = ['equity' => [], 'debt' => []];
        foreach ($capital as $year => $total) {
            $funding['equity'][$year] = $total ? $equityYear[$year] / $total * 100 : 0;
            $funding['debt'][$year] = $total ? $loanYear[$year] / $total * 100 : 0;
        }

        return [
            'months'          => $months,
            'years'           => $years,
            'vat'             => $vat,
            'corporate_tax'   => $corporateTax,
            'income'          => $income,
            'expense_lines'   => $expenseLines,
            'cogs_lines'      => $cogs,
            'cash'            => $cash,
            'cash_in'         => $cashIn,
            'cash_out'        => $cashOut,
            'summary'         => $summary,
            'balance'         => $balance,
            'lines'           => $lines,
            'working_capital' => ['changes' => $change, 'net' => $net],
            'funding'         => $funding,
        ];
    }

    // ── 3. Saving ───────────────────────────────────────────────────────────

    /** @param array<string,mixed> $built */
    private function persist(Project $project, array $built): void
    {
        $years = $built['years'];
        $yearKeys = array_keys($years);
        $income = $built['income'];
        $revenueYear = F::byYear($income['revenue'], $years, 'sum');

        $perYear = fn (array $series) => F::byYear($series, $years, 'sum');
        $percentYear = fn (array $series) => F::percentOf($revenueYear, F::byYear($series, $years, 'sum'), $yearKeys);

        $growth = [];
        $previous = null;
        foreach ($revenueYear as $year => $value) {
            $growth[$year] = $previous ? ($value - $previous) / $previous * 100 : 0;
            $previous = $value;
        }

        $project->update([
            'vat_statements'            => ['monthly' => $built['vat']],
            'corporate_taxes_statement' => ['monthly' => $built['corporate_tax']],
        ]);

        IncomeStatement::updateOrCreate(['project_id' => $project->id], [
            'total_sales_revenues'                      => $income['revenue'],
            'annually_sales_revenues_growth_rates'      => $growth,
            'gross_profit'                              => $income['gross_profit'],
            'annually_gross_profit_revenue_percentages' => $percentYear($income['gross_profit']),
            'ebitda'                                    => $income['ebitda'],
            'annually_ebitda_revenue_percentages'       => $percentYear($income['ebitda']),
            'ebit'                                      => $income['ebit'],
            'annually_ebit_revenue_percentages'         => $percentYear($income['ebit']),
            'ebt'                                       => $income['ebt'],
            'monthly_ebt'                               => $income['ebt'],
            'annually_ebt_revenue_percentages'          => $percentYear($income['ebt']),
            'net_profit'                                => $income['net_profit'],
            'annually_net_profit'                       => $perYear($income['net_profit']),
            'annually_net_profit_revenue_percentages'   => $percentYear($income['net_profit']),
            'accumulated_retained_earnings'             => $income['retained_earnings'],
            'total_depreciation'                        => $income['depreciation'],
            'total_cogs'                                => $income['cogs'],
            'total_percentages_cogs'                    => $percentYear($income['cogs']),
            'sganda'                                    => $income['sganda'],
            'sganda_revenues_percentages'               => $percentYear($income['sganda']),
        ]);

        $summary = $built['summary'];
        CashInOutStatement::updateOrCreate(['project_id' => $project->id], [
            'cash_end_balance'          => $summary['cash_end_balance'],
            'working_capital_injection' => $summary['working_capital_injection'],
            'equity_injection'          => $summary['equity_injection'],
            'loan_withdrawal'           => $summary['loan_withdrawal'],
            'customer_collection'       => $summary['customer_collection'],
            'supplier_payments'         => $summary['supplier_payments'],
            'taxes'                     => $summary['taxes'],
            'expenses'                  => $summary['expenses'],
            'fixed_asset_payments'      => $summary['fixed_asset_payments'],
            'loan_installments'         => $summary['loan_installments'],
            'total_cash_out'            => $summary['total_cash_out'],
        ]);

        $lines = $built['lines'];
        $balance = $built['balance'];
        $endOfYear = fn (array $series) => F::byYear($series, $years, 'end');
        $changes = $built['working_capital']['changes'];

        BalanceSheet::updateOrCreate(['project_id' => $project->id], [
            'supplier_payables'                => $endOfYear($lines['supplier_payables']),
            'other_debtors'                    => $endOfYear($lines['other_debtors']),
            'raw_material_inventory'           => $endOfYear($lines['rm_inventory']),
            'total_fgs'                        => $endOfYear($lines['fg_inventory']),
            'customer_receivables'             => $endOfYear($lines['customer_receivables']),
            'cash_and_banks'                   => $endOfYear($lines['cash']),
            'total_current_liabilities'        => $endOfYear($balance['total_current_liabilities']),
            'total_current_assets'             => $endOfYear($balance['total_current_assets']),
            'change_in_customer_receivables'   => $changes['customer_receivables'],
            'change_in_fg_inventory'           => $changes['fg_inventory'],
            'change_in_raw_material_inventory' => $changes['rm_inventory'],
            'change_in_other_debtors'          => $changes['other_debtors'],
            'change_in_supplier_payables'      => $changes['supplier_payables'],
            'change_in_other_creditors'        => $changes['other_creditors'],
            'net_change_in_working_capital'    => $built['working_capital']['net'],
            'debit_funding_percentages'        => $built['funding']['debt'],
            'equity_funding_percentages'       => $built['funding']['equity'],
        ]);
    }

    // ── 4. The screen ───────────────────────────────────────────────────────

    /**
     * Rows for the three tabs. Each row has the monthly figures and the yearly figure ('sum' for flows, 'end' for balances,
     * 'start' for the retained earnings, 'percent' for ratios worked out from the yearly figures).
     *
     * @param  array<string,mixed>  $built
     * @return array<string,mixed>
     */
    public function present(array $built, Timeline $timeline): array
    {
        $years = $built['years'];
        $yearKeys = array_keys($years);
        $income = $built['income'];
        $balance = $built['balance'];
        $lines = $built['lines'];
        $summary = $built['summary'];
        $cash = $built['cash'];

        $row = function (string $key, array $monthly, string $how = 'sum', string $style = 'normal') use ($years) {
            return ['key' => $key, 'style' => $style, 'monthly' => (object) $monthly, 'yearly' => (object) F::byYear($monthly, $years, $how)];
        };
        $ratio = function (string $key, array $top, array $base) use ($years, $yearKeys) {
            $monthly = F::percentOf($base, $top, array_keys($base));
            $yearly = F::percentOf(F::byYear($base, $years, 'sum'), F::byYear($top, $years, 'sum'), $yearKeys);

            return ['key' => $key, 'style' => 'percent', 'monthly' => (object) $monthly, 'yearly' => (object) $yearly];
        };

        $incomeRows = [
            $row('revenue', $income['revenue'], 'sum', 'total'),
            $row('cogs_raw_material', $built['cogs_lines']['raw_material']),
            $row('cogs_direct_labor', $built['cogs_lines']['direct_labor']),
            $row('cogs_manufacturing_overheads', $built['cogs_lines']['manufacturing_overheads']),
            $row('cogs', $income['cogs'], 'sum', 'total'),
            $row('gross_profit', $income['gross_profit'], 'sum', 'total'),
            $ratio('gross_margin', $income['gross_profit'], $income['revenue']),
        ];
        foreach ($built['expense_lines'] as $category => $series) {
            $incomeRows[] = $row('expense:'.$category, $series);
        }
        $incomeRows = array_merge($incomeRows, [
            $row('sganda', $income['sganda'], 'sum', 'total'),
            $row('ebitda', $income['ebitda'], 'sum', 'total'),
            $ratio('ebitda_margin', $income['ebitda'], $income['revenue']),
            $row('depreciation', $income['depreciation']),
            $row('ebit', $income['ebit'], 'sum', 'total'),
            $row('finance', $income['finance']),
            $row('ebt', $income['ebt'], 'sum', 'total'),
            $row('tax', $income['tax']),
            $row('net_profit', $income['net_profit'], 'sum', 'total'),
            $ratio('net_margin', $income['net_profit'], $income['revenue']),
        ]);

        $cashRows = [
            $row('cash_beginning', $cash['beginning_balance'], 'start'),
            $row('customer_collection', $summary['customer_collection']),
            $row('equity_injection', $summary['equity_injection']),
            $row('loan_withdrawal', $summary['loan_withdrawal']),
            $row('supplier_payments', $summary['supplier_payments']),
            $row('expenses', $summary['expenses']),
            $row('taxes', $summary['taxes']),
            $row('fixed_asset_payments', $summary['fixed_asset_payments']),
            $row('loan_installments', $summary['loan_installments']),
            $row('total_cash_in', $cash['cash_in'], 'sum', 'total'),
            $row('total_cash_out', $cash['cash_out'], 'sum', 'total'),
            $row('working_capital_injection', $cash['injection']),
            $row('cash_end', $cash['end_balance'], 'end', 'total'),
        ];

        $balanceRows = [
            $row('cash', $lines['cash'], 'end'),
            $row('customer_receivables', $lines['customer_receivables'], 'end'),
            $row('rm_inventory', $lines['rm_inventory'], 'end'),
            $row('fg_inventory', $lines['fg_inventory'], 'end'),
            $row('other_debtors', $lines['other_debtors'], 'end'),
            $row('vat_receivable', $balance['vat_receivable'], 'end'),
            $row('total_current_assets', $balance['total_current_assets'], 'end', 'total'),
            $row('projects_under_progress', $lines['projects_under_progress'], 'end'),
            $row('net_fixed_assets', $lines['net_fixed_assets'], 'end'),
            $row('total_assets', $balance['total_assets'], 'end', 'total'),
            $row('supplier_payables', $lines['supplier_payables'], 'end'),
            $row('fixed_asset_creditors', $lines['fixed_asset_creditors'], 'end'),
            $row('vat', $balance['vat_payable'], 'end'),
            $row('credit_withhold', $lines['credit_withhold'], 'end'),
            $row('salary_taxes', $lines['salary_taxes'], 'end'),
            $row('corporate_tax', $lines['corporate_tax'], 'end'),
            $row('other_creditors', $lines['other_creditors'], 'end'),
            $row('total_current_liabilities', $balance['total_current_liabilities'], 'end', 'total'),
            $row('loans', $lines['loans'], 'end'),
            $row('other_long_term', $lines['other_long_term'], 'end'),
            $row('total_long_term_liabilities', $balance['total_long_term_liabilities'], 'end', 'total'),
            $row('paid_up_capital', $lines['paid_up_capital'], 'end'),
            $row('additional_paid_up_capital', $lines['additional_paid_up_capital'], 'end'),
            $row('legal_reserve', $lines['legal_reserve'], 'end'),
            $row('retained_earnings', $lines['retained_earnings'], 'start'),
            $row('profit_of_period', $lines['profit_of_period'], 'sum'),
            $row('total_equity', $balance['total_equity'], 'end', 'total'),
        ];

        // The yearly check is worked out from the yearly figures (retained earnings at the start of the year + the year's profit).
        $yearly = fn (string $key) => (array) collect($balanceRows)->firstWhere('key', $key)['yearly'];
        $assetsYear = $yearly('total_assets');
        $rightYear = F::add([$yearly('total_current_liabilities'), $yearly('total_long_term_liabilities'),
            $yearly('paid_up_capital'), $yearly('additional_paid_up_capital'), $yearly('legal_reserve'), $yearly('retained_earnings'), $yearly('profit_of_period')], $yearKeys);
        $balanceRows[] = ['key' => 'check', 'style' => 'check', 'monthly' => (object) $balance['check'], 'yearly' => (object) F::subtract($assetsYear, [$rightYear], $yearKeys)];

        $maxGap = 0.0;
        foreach ($balance['check'] as $gap) {
            $maxGap = max($maxGap, abs($gap));
        }

        $revenueTotal = array_sum($income['revenue']);

        return [
            'years'  => array_map(fn ($year, $ms) => [
                'index' => $year,
                'year'  => (int) substr($timeline->dates[$ms[0]], 0, 4),
                'months' => array_map(fn ($m) => ['index' => $m, 'month' => (int) substr($timeline->dates[$m], 5, 2)], $ms),
            ], $yearKeys, $years),
            'tabs'   => ['income' => $incomeRows, 'cash' => $cashRows, 'balance' => $balanceRows],
            'check'  => ['balanced' => $maxGap < 0.5, 'gap' => round($maxGap, 2)],
            'tiles'  => [
                'revenue'    => $revenueTotal,
                'net_profit' => array_sum($income['net_profit']),
                'net_margin' => $revenueTotal ? array_sum($income['net_profit']) / $revenueTotal * 100 : 0,
                'cash_end'   => (float) (end($cash['end_balance']) ?: 0),
                'injection'  => array_sum($cash['injection']),
            ],
        ];
    }

    // ── Small helpers ───────────────────────────────────────────────────────

    /** @return array<int,float|int> the series with a 0 for every month that has no value */
    private function padded(array $series, array $months): array
    {
        $result = [];
        foreach ($months as $month) {
            $result[$month] = $series[$month] ?? 0;
        }
        foreach ($series as $month => $value) {
            $result[$month] ??= $value;
        }
        ksort($result);

        return $result;
    }

    /** A value that may be saved as JSON text or already an array. @return array<int|string,mixed> */
    private function jd(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /** Follows a path of keys into a saved column and gives back the series found there (empty when it is not there). @param list<string> $path @return array<int|string,mixed> */
    private function at(mixed $model, array $path): array
    {
        if ($model === null) {
            return [];
        }

        $value = $this->jd($model->{array_shift($path)});

        foreach ($path as $key) {
            $value = $this->jd($value[$key] ?? null);
        }

        return $value;
    }
}
