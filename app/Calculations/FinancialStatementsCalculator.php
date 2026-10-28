<?php

namespace App\Calculations;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — Income statement, cash flow and balance sheet
//  Location: app/Calculations/FinancialStatementsCalculator.php
//
//  Pure calculation, ported from the old application (Project::calculateIncomeStatement, getCashInOutFlowViewVars,
//  getBalanceSheetViewVars and the HArr helpers). It only adds and subtracts the monthly series that the earlier steps
//  already worked out (sales, costs, collections, payments, loans, VAT, taxes, opening balances); nothing is typed here.
//  Every series is "month index => value" over the months of the study (0 = the study's first month).
// ══════════════════════════════════════════════════════════════════

final class FinancialStatementsCalculator
{
    // ── Small helpers ───────────────────────────────────────────────────────

    /** Adds series together month by month over the given months. @param list<array<int,float|int>> $series @param list<int> $months */
    public static function add(array $series, array $months): array
    {
        $total = [];

        foreach ($months as $month) {
            $value = 0;
            foreach ($series as $one) {
                $value += $one[$month] ?? 0;
            }
            $total[$month] = $value;
        }

        return $total;
    }

    /** First series minus the others, month by month. */
    public static function subtract(array $first, array $others, array $months): array
    {
        $result = [];

        foreach ($months as $month) {
            $value = $first[$month] ?? 0;
            foreach ($others as $other) {
                $value -= $other[$month] ?? 0;
            }
            $result[$month] = $value;
        }

        return $result;
    }

    /** Running total: what has been added up to and including each month. */
    public static function accumulate(array $series, array $months): array
    {
        $running = 0;
        $result = [];

        foreach ($months as $month) {
            $running += $series[$month] ?? 0;
            $result[$month] = $running;
        }

        return $result;
    }

    /** Percentage of the sales of the same month (0 when there are no sales). */
    public static function percentOf(array $sales, array $series, array $months): array
    {
        $result = [];

        foreach ($months as $month) {
            $base = $sales[$month] ?? 0;
            $result[$month] = $base ? ($series[$month] ?? 0) / $base * 100 : 0;
        }

        return $result;
    }

    /**
     * A monthly series turned into one figure per year.
     *
     * @param  array<int,list<int>>  $years  year index => month indexes in order
     * @param  string  $how  'sum' (flows), 'end' (balances: the last month), 'start' (the first month)
     * @return array<int,float|int>  year index => figure
     */
    public static function byYear(array $series, array $years, string $how): array
    {
        $result = [];

        foreach ($years as $year => $months) {
            if ($months === []) {
                continue;
            }
            $result[$year] = match ($how) {
                'end'   => $series[end($months)] ?? 0,
                'start' => $series[$months[0]] ?? 0,
                default => array_sum(array_map(fn ($m) => $series[$m] ?? 0, $months)),
            };
        }

        return $result;
    }

    // ── Income statement ────────────────────────────────────────────────────

    /**
     * @param array{
     *   months:list<int>,
     *   revenue:array<string|int,array<int,float|int>>,
     *   cogs:array{raw_material:array,direct_labor:array,manufacturing_overheads:array},
     *   expenses:array<string,array<int,float|int>>,
     *   cogs_depreciation:array<int,float|int>,
     *   admin_depreciation:array<int,float|int>,
     *   finance:array<string|int,array<int,float|int>>,
     *   tax_rate:float, tax_basis:string, years:array<int,list<int>>, tax_booked_at:array<int,int>,
     *   opening_retained_earnings:float
     * } $in
     * @return array<string,mixed>
     */
    public static function incomeStatement(array $in): array
    {
        $months = $in['months'];

        $revenue = self::add(array_values($in['revenue']), $months);
        $cogs = self::add(array_values($in['cogs']), $months);
        $grossProfit = self::subtract($revenue, [$cogs], $months);
        $sganda = self::add(array_values($in['expenses']), $months);

        $depreciation = self::add([$in['cogs_depreciation'], $in['admin_depreciation']], $months);
        // EBITDA adds the depreciation back; EBIT is what is left after it (it is already inside the costs above).
        $ebitda = self::subtract(self::add([$grossProfit, $depreciation], $months), [$sganda], $months);
        $ebit = self::subtract($grossProfit, [$sganda], $months);

        $finance = self::add(array_values($in['finance']), $months);
        $ebt = self::subtract($ebit, [$finance], $months);

        // Tax is worked out on every month of the calendar years (also the months after the study's end for a part year).
        $taxPeriods = TaxCalculator::corporateTax($ebt, $in['years'], $in['tax_booked_at'], (float) $in['tax_rate'], $in['tax_basis']);
        $tax = [];
        foreach ($months as $month) {
            $tax[$month] = $taxPeriods[$month] ?? 0;
        }

        $netProfit = self::subtract($ebt, [$tax], $months);

        // The profit of the months before is in the retained earnings; the profit of the month itself is shown apart.
        $retained = [];
        $running = (float) $in['opening_retained_earnings'];
        foreach ($months as $i => $month) {
            if ($i > 0) {
                $running += $netProfit[$months[$i - 1]] ?? 0;
            }
            $retained[$month] = $running;
        }

        return [
            'revenue'            => $revenue,
            'cogs'               => $cogs,
            'gross_profit'       => $grossProfit,
            'sganda'             => $sganda,
            'depreciation'       => $depreciation,
            'ebitda'             => $ebitda,
            'ebit'               => $ebit,
            'finance'            => $finance,
            'ebt'                => $ebt,
            'tax'                => $tax,
            'tax_all_periods'    => $taxPeriods,
            'net_profit'         => $netProfit,
            'retained_earnings'  => $retained,
        ];
    }

    // ── Cash flow ───────────────────────────────────────────────────────────

    /**
     * Cash coming in and going out month by month, and the cash balance. When a month would end with less than nothing,
     * the owners put in that missing amount ("working capital injection") so the balance is never negative.
     *
     * @param array{months:list<int>, opening_cash:float, cash_in:array<string|int,array<int,float|int>>, cash_out:array<string|int,array<int,float|int>>} $in
     * @return array<string,array<int,float|int>>
     */
    public static function cashFlow(array $in): array
    {
        $months = $in['months'];
        $cashIn = self::add(array_values($in['cash_in']), $months);
        $cashOut = self::add(array_values($in['cash_out']), $months);

        $balance = (float) $in['opening_cash'];
        $result = ['cash_in' => $cashIn, 'cash_out' => $cashOut, 'beginning_balance' => [], 'net_before_injection' => [], 'injection' => [], 'end_balance' => []];

        foreach ($months as $month) {
            $net = $balance + $cashIn[$month] - $cashOut[$month];
            $injection = $net < 0 ? -$net : 0;
            $end = $net + $injection;

            $result['beginning_balance'][$month] = $balance;
            $result['net_before_injection'][$month] = $net;
            $result['injection'][$month] = $injection;
            $result['end_balance'][$month] = $end;

            $balance = $end;
        }

        return $result;
    }

    // ── Balance sheet ───────────────────────────────────────────────────────

    /**
     * @param array{
     *   months:list<int>, net_fixed_assets:array, projects_under_progress:array, cash:array, customer_receivables:array,
     *   fg_inventory:array, rm_inventory:array, other_debtors:array,
     *   supplier_payables:array, fixed_asset_creditors:array, vat:array, credit_withhold:array, salary_taxes:array,
     *   corporate_tax:array, other_creditors:array, loans:array, other_long_term:array,
     *   paid_up_capital:array, additional_paid_up_capital:array, legal_reserve:array, retained_earnings:array, profit_of_period:array
     * } $in
     * @return array<string,array<int,float|int>>
     */
    public static function balanceSheet(array $in): array
    {
        $months = $in['months'];

        // VAT: a balance above 0 is owed to the tax authority (a liability); below 0 it is a credit the authority owes the company
        // (an asset, "VAT receivable"). Month by month it can be either, so each side takes its own part.
        $vatPayable = $vatReceivable = [];
        foreach ($months as $month) {
            $vat = $in['vat'][$month] ?? 0;
            $vatPayable[$month] = max($vat, 0);
            $vatReceivable[$month] = max(-$vat, 0);
        }

        $currentAssets = self::add([$in['cash'], $in['customer_receivables'], $in['rm_inventory'], $in['fg_inventory'], $in['other_debtors'], $vatReceivable], $months);
        $totalAssets = self::add([$currentAssets, $in['projects_under_progress'], $in['net_fixed_assets']], $months);

        $currentLiabilities = self::add([
            $in['supplier_payables'], $in['fixed_asset_creditors'], $vatPayable, $in['credit_withhold'],
            $in['salary_taxes'], $in['corporate_tax'], $in['other_creditors'],
        ], $months);
        $longTermLiabilities = self::add([$in['loans'], $in['other_long_term']], $months);

        $equity = self::add([$in['paid_up_capital'], $in['additional_paid_up_capital'], $in['legal_reserve'], $in['retained_earnings'], $in['profit_of_period']], $months);
        $check = self::subtract($totalAssets, [$currentLiabilities, $longTermLiabilities, $equity], $months);

        return [
            'vat_payable'               => $vatPayable,
            'vat_receivable'            => $vatReceivable,
            'total_current_assets'      => $currentAssets,
            'total_assets'              => $totalAssets,
            'total_current_liabilities' => $currentLiabilities,
            'total_long_term_liabilities' => $longTermLiabilities,
            'total_equity'              => $equity,
            'check'                     => $check,
        ];
    }

    // ── Change in working capital (per year) ────────────────────────────────

    /**
     * How much the balance of an item changed during each year, as cash: a rise in an ASSET (customers, stock, debtors) takes
     * cash ("cash effect" = before - after); a rise in a LIABILITY (suppliers, creditors) gives cash (after - before).
     *
     * @param  array<int,float|int>  $balance  month index => balance at the end of the month
     * @param  array<int,int>        $yearEnds year index => the last month of the year
     * @return array<int,float|int>  year index => cash effect of the year
     */
    public static function yearlyChange(array $balance, float $opening, array $yearEnds, bool $isAsset): array
    {
        $result = [];
        $previous = $opening;

        foreach ($yearEnds as $year => $lastMonth) {
            $current = $balance[$lastMonth] ?? 0;
            $result[$year] = $isAsset ? $previous - $current : $current - $previous;
            $previous = $current;
        }

        return $result;
    }
}
