<?php

namespace App\Calculations;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — Expense lines
//  Location: app/Calculations/ExpenseCalculator.php
//
//  Four kinds of expense, as in the old application:
//    · fixed monthly   an amount every month that grows by a % every 3, 6 or 12 months
//    · one time        an amount paid in one month and spread (amortised) over a number of months
//    · % of revenue    a % of the monthly sales value of the chosen products
//    · cost per unit   an amount for every unit sold of the chosen products
//  and how each is paid: cash (same month), quarterly / semi-annually / annually (the amounts of a block of months are
//  paid together in the block's FIRST month, as the old application did) or customised (rate % after a number of days).
//  Every series is "month index => number".
// ══════════════════════════════════════════════════════════════════

final class ExpenseCalculator
{
    public const TERMS = ['cash', 'quarterly', 'semi-annually', 'annually', 'customize'];

    private const BLOCK = ['cash' => 1, 'quarterly' => 3, 'semi-annually' => 6, 'annually' => 12];

    private const INCREASE_EVERY = ['quarterly' => 3, 'semi-annually' => 6, 'annually' => 12];

    /**
     * An amount every month from $start to $end, increased by $increasePercent every 3 / 6 / 12 months.
     * VAT is added unless it is deductible (recoverable), in which case the expense is the amount before VAT.
     *
     * @return array{total_before_vat:array<int,float>, total_vat:array<int,float>, total_after_vat:array<int,float>, withhold_amounts:array<int,float>}
     */
    public static function fixedMonthly(float $amount, int $start, int $end, string $increaseInterval, float $increasePercent, bool $deductible, float $vatPercent, float $withholdPercent): array
    {
        $every = self::INCREASE_EVERY[$increaseInterval] ?? 12;
        $before = [];
        $vat = [];
        $after = [];
        $withhold = [];
        $current = $amount;

        for ($month = $start, $counter = 0; $month <= $end; $month++, $counter++) {
            if ($counter !== 0 && $counter % $every === 0) {
                $current *= 1 + $increasePercent / 100;
            }
            $before[$month] = $current;
            $after[$month] = $deductible ? $current : $current * (1 + $vatPercent / 100);
            $vat[$month] = $after[$month] - $before[$month];
            $withhold[$month] = $current * $withholdPercent / 100;
        }

        return ['total_before_vat' => $before, 'total_vat' => $vat, 'total_after_vat' => $after, 'withhold_amounts' => $withhold];
    }

    /**
     * An amount (plus VAT unless deductible) spread equally over $amortizationMonths months from $start.
     *
     * @return array{monthly_one_time:array<int,float>, one_time:array<int,float>, end_balance:array<int,float>}
     */
    public static function oneTime(float $amount, int $amortizationMonths, int $start, bool $deductible, float $vatPercent): array
    {
        $months = max(1, $amortizationMonths);
        $total = $deductible ? $amount : $amount * (1 + $vatPercent / 100);
        $monthly = [];
        $beginning = [];
        $end = [];
        $balance = $total;

        for ($i = 0; $i < $months; $i++) {
            $monthly[$start + $i] = $total / $months;
            $beginning[$start + $i] = $balance;
            $balance -= $monthly[$start + $i];
            $end[$start + $i] = $balance;
        }

        return ['monthly_one_time' => $monthly, 'one_time' => $beginning, 'end_balance' => $end];
    }

    /**
     * A % of the monthly sales value (or an amount per unit sold) of the chosen products, between two months.
     *
     * @param  array<int,array<int,float|int>>  $seriesByProduct  product id => month index => sales value (or units sold)
     * @return array{expense_amounts:array<int,float>, expense_allocations:array<int,array<int,float>>}
     */
    public static function perProductBase(array $seriesByProduct, int $start, int $end, float $factor): array
    {
        $total = [];
        $perProduct = [];

        foreach ($seriesByProduct as $productId => $series) {
            foreach ($series as $month => $base) {
                if ($month >= $start && $month <= $end) {
                    $value = $factor * $base;
                    $total[$month] = ($total[$month] ?? 0) + $value;
                    $perProduct[$productId][$month] = $value;
                }
            }
        }

        return ['expense_amounts' => $total, 'expense_allocations' => $perProduct];
    }

    /** % of revenue: the percentage of each month's sales value. */
    public static function percentageOfRevenue(array $salesValuesByProduct, int $start, int $end, float $percent): array
    {
        return self::perProductBase($salesValuesByProduct, $start, $end, $percent / 100);
    }

    /** Cost per unit: the cost of each unit sold. */
    public static function costPerUnit(array $unitsSoldByProduct, int $start, int $end, float $costOfUnit): array
    {
        return self::perProductBase($unitsSoldByProduct, $start, $end, $costOfUnit);
    }

    /**
     * What is paid when, from what falls due.
     *
     * @param  array<int,float|int>  $amounts  month index => amount due
     * @param  array<int,float>      $policy   (customize only) due days => payment rate %
     * @return array<int,float|int>            month index => amount paid
     */
    public static function payments(string $terms, array $policy, array $amounts, Timeline $timeline): array
    {
        ksort($amounts);

        if ($terms === 'customize') {
            $paid = CollectionPolicy::apply(array_filter($policy, fn ($rate) => (float) $rate !== 0.0), $amounts);
        } else {
            $block = self::BLOCK[$terms] ?? 1;
            $paid = [];
            $position = 0;
            $first = null;

            foreach ($amounts as $month => $value) {
                if ($position % $block === 0) {
                    $first = $month;
                }
                $paid[$first] = ($paid[$first] ?? 0) + $value;
                $position++;
            }
        }

        // Money that would only arrive after the last month of the study is not counted.
        return array_filter($paid, fn ($month) => isset($timeline->dates[$month]), ARRAY_FILTER_USE_KEY);
    }

    /**
     * The payable statement of an expense: opening balance + expense + VAT = total due; less the payments and the
     * withholding tax = closing balance, per month / quarter / half-year / year.
     *
     * @return array<string,array<string,array<int,float|int>>>
     */
    public static function statement(array $expenseBeforeVat, array $vat, array $payments, array $withholdPayments, Timeline $timeline): array
    {
        $net = Series::subtract($payments, [$withholdPayments], array_unique(array_merge(array_keys($payments), array_keys($withholdPayments))));

        return CollectionPolicy::buildStatement($expenseBeforeVat, $vat, $net, $withholdPayments, $timeline);
    }
}
