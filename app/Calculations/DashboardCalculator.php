<?php

namespace App\Calculations;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — DashboardCalculator (the numbers on the Dashboard)
//  Location: app/Calculations/DashboardCalculator.php
//
//  Pure maths, no database. Everything is ported from the old Project::getDashboardViewVars():
//    · free cash flow of a year = EBIT + depreciation + net change in working capital − tax on EBIT − capex
//      (tax on EBIT only when EBIT is positive);
//    · WACC of a year = equity % × return rate + debt % × cost of debt;
//    · terminal value (last year only) = last free cash flow × (1 + g) ÷ (WACC of the last year − g);
//    · discount factor of year n (1, 2, 3 …) = (1 + WACC of that year) ^ n;   NPV = Σ cash flow ÷ discount factor;
//    · IRR = the rate that makes the yearly cash flow (with the terminal value) add up to 0, first year counted as period 0;
//    · payback: the monthly "collections − cash out" is continued ten more years (last 12 months repeated, growing by g),
//      added up, and the payback is the first month the total reaches the equity put in;
//    · liquidity ratios from the year-end balance sheet; DSO / DIO / DPO from the average of the monthly balances.
//  Changes from the old application (reported to the owner):
//    · if WACC is not above the growth rate the terminal value is left out (the old one divided by zero or went negative);
//    · cost of debt is weighted by the loan share of every asset (counts × amount × (100 − equity %)).
// ══════════════════════════════════════════════════════════════════

final class DashboardCalculator
{
    /** How many months the cash flow is continued to look for the payback. */
    public const PAYBACK_HORIZON = 240;

    /**
     * Yearly figures of the dashboard.
     *
     * @param array{
     *   years:array<int,list<int>>,
     *   revenue:array, gross_profit:array, ebitda:array, ebit:array, net_profit:array,
     *   cogs_lines:array<string,array>, expense_lines:array<string,array>,
     *   depreciation:array, net_change_working_capital:array<int,float|int>, capex:array,
     *   tax_rate:float, perpetual_growth_rate:float, return_rate:float, cost_of_debt:float,
     *   equity_funding:array<int,float|int>, debt_funding:array<int,float|int>
     * } $in
     * @return array<string,mixed>
     */
    public static function results(array $in): array
    {
        // The charts and tables follow `periods` (one per month for a study of one year, otherwise one per year);
        // the discounted cash flow always works on whole years.
        $years = $in['periods'] ?? $in['years'];
        $keys = array_keys($years);
        $by = fn (array $series, string $how = 'sum') => FinancialStatementsCalculator::byYear($series, $years, $how);

        $revenue = $by($in['revenue']);
        $percent = fn (array $yearly) => FinancialStatementsCalculator::percentOf($revenue, $yearly, $keys);

        $growth = [];
        $previous = null;
        foreach ($revenue as $year => $value) {
            $growth[$year] = $previous ? ($value - $previous) / $previous * 100 : 0;
            $previous = $value;
        }

        $accumulated = [];
        $running = 0.0;
        foreach ($revenue as $year => $value) {
            $running += $value;
            $accumulated[$year] = $running;
        }

        $profit = [];
        foreach (['gross_profit', 'ebitda', 'ebit', 'net_profit'] as $key) {
            $profit[$key] = ['value' => $by($in[$key]), 'percent' => $percent($by($in[$key]))];
        }

        // Costs as a % of the revenue of the year.
        $costs = [];
        $costSources = [
            'raw_material'            => $in['cogs_lines']['raw_material'] ?? [],
            'direct_labor'            => $in['cogs_lines']['direct_labor'] ?? [],
            'manufacturing_overheads' => $in['cogs_lines']['manufacturing_overheads'] ?? [],
            'marketing'               => $in['expense_lines']['marketing'] ?? [],
            'sales'                   => $in['expense_lines']['sales'] ?? [],
            'general'                 => $in['expense_lines']['general-expenses'] ?? [],
        ];
        foreach ($costSources as $key => $series) {
            $yearly = $by($series);
            $costs[$key] = ['value' => $yearly, 'percent' => $percent($yearly)];
        }

        return [
            'years'       => $keys,
            'revenue'     => $revenue,
            'growth'      => $growth,
            'accumulated' => $accumulated,
            'profit'      => $profit,
            'costs'       => $costs,
            'dcf'         => self::dcf($in, fn (array $series, string $how = 'sum') => FinancialStatementsCalculator::byYear($series, $in['years'], $how)),
        ];
    }

    /** @return array<string,mixed> */
    private static function dcf(array $in, \Closure $by): array
    {
        $years = $in['years'];
        $keys = array_keys($years);
        $rate = (float) $in['tax_rate'] / 100;
        $growth = (float) $in['perpetual_growth_rate'] / 100;

        $ebit = $by($in['ebit']);
        $depreciation = $by($in['depreciation']);
        $capex = $by($in['capex']);

        $taxes = $free = [];
        foreach ($keys as $year) {
            $taxes[$year] = ($ebit[$year] ?? 0) > 0 ? $ebit[$year] * $rate : 0;
            $free[$year] = ($ebit[$year] ?? 0) + ($depreciation[$year] ?? 0) + ($in['net_change_working_capital'][$year] ?? 0) - $taxes[$year] - ($capex[$year] ?? 0);
        }

        $wacc = [];
        foreach ($keys as $year) {
            $wacc[$year] = ($in['equity_funding'][$year] ?? 0) / 100 * ((float) $in['return_rate'] / 100)
                + ($in['debt_funding'][$year] ?? 0) / 100 * (float) $in['cost_of_debt'];
        }

        $last = $keys === [] ? null : end($keys);
        $terminal = array_fill_keys($keys, 0.0);
        $terminalOk = true;
        if ($last !== null && $wacc[$last] > $growth) {
            $terminal[$last] = $free[$last] * (1 + $growth) / ($wacc[$last] - $growth);
        } elseif ($last !== null) {
            $terminalOk = false;
        }

        $withTerminal = $discount = $discounted = [];
        $npv = 0.0;
        $n = 1;
        foreach ($keys as $year) {
            $withTerminal[$year] = $free[$year] + $terminal[$year];
            $discount[$year] = (1 + $wacc[$year]) ** $n;
            $discounted[$year] = $withTerminal[$year] / $discount[$year];
            $npv += $discounted[$year];
            $n++;
        }

        return [
            'ebit'              => $ebit,
            'taxes'             => $taxes,
            'depreciation'      => $depreciation,
            'working_capital'   => array_intersect_key($in['net_change_working_capital'], array_flip($keys)),
            'capex'             => $capex,
            'free_cash_flow'    => $free,
            'wacc'              => $wacc,
            'terminal_value'    => $terminal,
            'terminal_ok'       => $terminalOk,
            'with_terminal'     => $withTerminal,
            'discount_factor'   => $discount,
            'discounted'        => $discounted,
            'terminal_pv'       => $last === null ? 0.0 : $terminal[$last] / $discount[$last],
            'npv'               => $npv,
            'irr'               => self::irr(array_values($withTerminal)),
        ];
    }

    /**
     * The rate (in %) at which the cash flows add up to 0, the first one counted as period 0.
     * Null when it cannot exist (all flows of one sign) or is not found.
     *
     * @param list<float|int> $flows
     */
    public static function irr(array $flows): ?float
    {
        $hasPositive = $hasNegative = false;
        foreach ($flows as $flow) {
            $hasPositive = $hasPositive || $flow > 0;
            $hasNegative = $hasNegative || $flow < 0;
        }
        if (! $hasPositive || ! $hasNegative) {
            return null;
        }

        $value = function (float $rate) use ($flows): float {
            $total = 0.0;
            foreach ($flows as $t => $flow) {
                $total += $flow / ((1 + $rate) ** $t);
            }

            return $total;
        };

        // The root is searched between −99 % and a very high rate: scan for a change of sign, then halve the gap.
        $low = -0.99;
        $high = null;
        $previousRate = $low;
        $previous = $value($low);
        foreach ([-0.9, -0.75, -0.5, -0.25, 0, 0.05, 0.1, 0.2, 0.35, 0.5, 0.75, 1, 2, 5, 10, 50, 100, 1000] as $rate) {
            $current = $value($rate);
            if ($previous == 0.0) {
                return $previousRate * 100;
            }
            if ($previous * $current < 0) {
                $low = $previousRate;
                $high = $rate;
                break;
            }
            $previousRate = $rate;
            $previous = $current;
        }
        if ($high === null) {
            return null;
        }

        $valueLow = $value($low);
        for ($i = 0; $i < 200; $i++) {
            $mid = ($low + $high) / 2;
            $valueMid = $value($mid);
            if ($valueLow * $valueMid <= 0) {
                $high = $mid;
            } else {
                $low = $mid;
                $valueLow = $valueMid;
            }
        }

        return ($low + $high) / 2 * 100;
    }

    /**
     * Months until the money put in by the owners comes back (null when it never does within the horizon).
     *
     * @param array<int,float|int> $collections  monthly customer collections
     * @param array<int,float|int> $cashOut      monthly total cash out
     * @return array{month:int|null, months:int|null, years:float|null}  month = index of the payback month
     */
    public static function payback(array $collections, array $cashOut, float $equity, float $growth, int $studyMonths): array
    {
        $flow = [];
        for ($m = 0; $m < $studyMonths; $m++) {
            $flow[$m] = ($collections[$m] ?? 0) - ($cashOut[$m] ?? 0);
        }

        // Continue the last 12 months, growing by the perpetual growth rate, until the horizon.
        while (count($flow) > 0 && count($flow) < self::PAYBACK_HORIZON) {
            $length = count($flow);
            $tail = array_slice($flow, max(0, $length - 12), null, false);
            foreach ($tail as $value) {
                $flow[] = $value * (1 + $growth);
            }
        }

        $total = 0.0;
        foreach (array_values($flow) as $month => $value) {
            $total += $value;
            if ($total >= 0 && $total >= $equity) {
                return ['month' => $month, 'months' => $month + 1, 'years' => round(($month + 1) / 12, 1)];
            }
        }

        return ['month' => null, 'months' => null, 'years' => null];
    }

    /**
     * Liquidity and activity ratios per year.
     *
     * @param array{
     *   years:array<int,list<int>>, revenue:array, gross_profit:array,
     *   current_assets:array, current_liabilities:array, cash:array, receivables:array, inventory:array, payables:array
     * } $in   current_assets … payables are monthly balances at the end of each month
     * @return array<string,array<int,float|int>>
     */
    public static function ratios(array $in): array
    {
        $years = $in['years'];
        $keys = array_keys($years);
        $end = fn (array $series) => FinancialStatementsCalculator::byYear($series, $years, 'end');
        $sum = fn (array $series) => FinancialStatementsCalculator::byYear($series, $years, 'sum');
        $divide = fn (array $top, array $bottom) => array_combine($keys, array_map(fn ($y) => ($bottom[$y] ?? 0) ? ($top[$y] ?? 0) / $bottom[$y] : 0, $keys));

        $assets = $end($in['current_assets']);
        $liabilities = $end($in['current_liabilities']);
        $cash = $end($in['cash']);
        $receivablesEnd = $end($in['receivables']);

        $quick = [];
        foreach ($keys as $year) {
            $quick[$year] = ($cash[$year] ?? 0) + ($receivablesEnd[$year] ?? 0);
        }

        $workingCapital = [];
        foreach ($keys as $year) {
            $workingCapital[$year] = ($assets[$year] ?? 0) - ($liabilities[$year] ?? 0);
        }

        $revenue = $sum($in['revenue']);
        $cogs = [];
        $grossProfit = $sum($in['gross_profit']);
        foreach ($keys as $year) {
            $cogs[$year] = ($revenue[$year] ?? 0) - ($grossProfit[$year] ?? 0);
        }

        // Days = (average monthly balance ÷ amount of the year) × (months of the year × 30)
        $days = function (array $balance, array $base) use ($years, $keys, $sum): array {
            $totals = $sum($balance);
            $result = [];
            foreach ($keys as $year) {
                $months = count($years[$year]);
                $amount = $base[$year] ?? 0;
                $result[$year] = $months && $amount ? ($totals[$year] ?? 0) / $months / $amount * ($months * 30) : 0;
            }

            return $result;
        };

        $dso = $days($in['receivables'], $revenue);
        $dio = $days($in['inventory'], $cogs);
        $dpo = $days($in['payables'], $cogs);
        $ccc = [];
        foreach ($keys as $year) {
            $ccc[$year] = $dso[$year] + $dio[$year] - $dpo[$year];
        }

        return [
            'current_ratio'   => $divide($assets, $liabilities),
            'quick_ratio'     => $divide($quick, $liabilities),
            'cash_ratio'      => $divide($cash, $liabilities),
            'working_capital' => $workingCapital,
            'dso'             => $dso,
            'dio'             => $dio,
            'dpo'             => $dpo,
            'ccc'             => $ccc,
        ];
    }

    /**
     * Cost of debt: the interest rate of every asset's loan, weighted by the size of that loan.
     *
     * @param list<array{amount:float|int, equity_rate:float|int, interest_rate:float|int}> $assets  amount = counts × unit amount
     */
    public static function costOfDebt(array $assets): float
    {
        $weights = [];
        $total = 0.0;
        foreach ($assets as $i => $asset) {
            $weights[$i] = $asset['amount'] * (100 - $asset['equity_rate']);
            $total += $weights[$i];
        }
        if ($total == 0.0) {
            return 0.0;
        }

        $cost = 0.0;
        foreach ($assets as $i => $asset) {
            $cost += $weights[$i] / $total * ($asset['interest_rate'] / 100);
        }

        return $cost;
    }
}
