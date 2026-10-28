<?php

namespace App\Calculations;

// ══════════════════════════════════════════════════════════════════
//  Collection (or payment) policy and statement.
//
//  A policy is   ['cash_payment' => %, 'rate' => [% , %], 'due_in_days' => [days, days]]
//  It says: this % is settled in the same month, this % after the first due days, this % after the second.
//  Rules kept from the old application:
//    · a due period of less than 30 days still lands in the same month; otherwise months = round(days / 30)
//      (45 days = 2 months, 75 days = 3 months);
//    · there are TWO policies: one for the first operation year and one for all the following years;
//    · VAT and withholding tax are collected on the same terms as the sale;
//    · export sales carry no VAT and no withholding tax (the caller passes 0 for the rates);
//    · money that would only arrive after the last month of the study is not counted.
// ══════════════════════════════════════════════════════════════════

final class CollectionPolicy
{
    public const DUE_DAYS = [15, 30, 45, 60, 75, 90, 120, 150, 180];

    /**
     * Turns a policy into "due days => % collected" (0 = the same month).
     *
     * @param  array<string,mixed>  $policy
     * @return array<int,float>
     */
    public static function dueDayRates(array $policy): array
    {
        $map = [0 => (float) ($policy['cash_payment'] ?? 0)];

        foreach ([0, 1] as $n) {
            $days = $policy['due_in_days'][$n] ?? null;
            $rate = $policy['rate'][$n] ?? null;

            if ($days === null || $days === '') {
                continue;
            }
            $days = (int) $days;
            $map[$days] = ($map[$days] ?? 0) + (float) ($rate ?? 0);
        }

        return $map;
    }

    public static function monthsLater(int $dueDays): int
    {
        return $dueDays < 30 ? 0 : (int) round($dueDays / 30);
    }

    /**
     * Spreads each month's amount over the months it is collected in.
     *
     * @param  array<int,float>  $dueDayRates
     * @param  array<int,float|int>  $amounts  month index => amount
     * @return array<int,float|int>
     */
    public static function apply(array $dueDayRates, array $amounts): array
    {
        $collected = [];

        foreach ($amounts as $index => $amount) {
            foreach ($dueDayRates as $days => $rate) {
                $target = $index + self::monthsLater((int) $days);
                $collected[$target] = ($collected[$target] ?? 0) + $amount * ($rate / 100);
            }
        }

        return $collected;
    }

    /**
     * Collection statement of one stream of sales (or of purchases).
     *
     * @param  array<int,float|int>  $base        month index => amount before VAT
     * @param  array<string,mixed>   $firstYear   policy of the first operation year
     * @param  array<string,mixed>   $laterYears  policy of the following years
     * @return array<string,array<string,array<int,float|int>>>  interval => column => month index => value
     */
    public static function statement(array $base, float $vatPercent, float $withholdPercent, array $firstYear, array $laterYears, Timeline $timeline): array
    {
        $vat = Series::scale($base, $vatPercent / 100);
        $withhold = Series::scale($base, $withholdPercent / 100);
        $afterVat = Series::sum([$base, $vat], array_keys($base));

        $byYear = $timeline->operationMonthsByYear();
        $firstMonths = $timeline->firstOperationYearMonths();
        $hasLaterYears = count($byYear) > 1;
        $laterStart = $firstMonths === [] ? 0 : max($firstMonths) + 1;

        $collect = function (array $amounts) use ($firstYear, $laterYears, $firstMonths, $hasLaterYears, $laterStart) {
            $first = self::apply(self::dueDayRates($firstYear), array_intersect_key($amounts, array_flip($firstMonths)));

            if (! $hasLaterYears) {
                return $first;
            }
            $later = self::apply(self::dueDayRates($laterYears), Series::from($amounts, $laterStart));

            return Series::sum([$first, $later], array_keys($amounts));
        };

        $collectedAfterVat = $collect($afterVat);
        $withholdPaid = $collect($withhold);
        $net = Series::subtract($collectedAfterVat, [$withholdPaid], array_keys($base));

        return self::buildStatement($base, $vat, $net, $withholdPaid, $timeline);
    }

    /**
     * The statement itself: opening balance + sales + VAT = total due; less what was paid = closing balance.
     *
     * @return array<string,array<string,array<int,float|int>>>
     */
    public static function buildStatement(array $expense, array $vat, array $net, array $withholdPaid, Timeline $timeline): array
    {
        $result = [];

        foreach (IntervalSums::INTERVALS as $interval) {
            $monthly = $interval === 'monthly';
            $expenseAt = $monthly ? $expense : IntervalSums::sum($expense, $interval, $timeline);
            $vatAt = $monthly ? $vat : IntervalSums::sum($vat, $interval, $timeline);
            $netAt = $monthly ? $net : IntervalSums::sum($net, $interval, $timeline);
            $withholdAt = $monthly ? $withholdPaid : IntervalSums::sum($withholdPaid, $interval, $timeline);

            $balance = 0;

            // Every period from the first expense to the last expense OR payment: what is paid after the last expense
            // (a one-time expense paid after 30 and 60 days, say) is still owed until it is paid, so the balance must be
            // shown in those periods too, not dropped after the month of the expense.
            $keys = array_merge(array_keys($expenseAt), array_keys($vatAt), array_keys($netAt), array_keys($withholdAt));
            $periods = $keys === [] ? [] : array_values(array_unique(array_merge(array_keys($expenseAt), IntervalSums::periodEnds($interval, min($keys), max($keys), $timeline))));
            sort($periods);

            foreach ($periods as $index) {
                $amount = $expenseAt[$index] ?? 0;
                $due = $amount + ($vatAt[$index] ?? 0) + $balance;
                $paid = $netAt[$index] ?? 0;
                $withheld = $withholdAt[$index] ?? 0;
                $end = $due - $paid - $withheld;

                $result[$interval]['beginning_balance'][$index] = $balance;
                $result[$interval]['expense'][$index] = $amount;
                $result[$interval]['vat'][$index] = $vatAt[$index] ?? 0;
                $result[$interval]['total_due'][$index] = $due;
                $result[$interval]['payment'][$index] = $paid;
                $result[$interval]['withhold_amount'][$index] = $withheld;
                $result[$interval]['end_balance'][$index] = $end;

                $balance = $end;
            }
        }

        return $result;
    }

    /** Adds two statements (local + export) column by column. */
    public static function addStatements(array $a, array $b): array
    {
        $result = [];

        foreach (IntervalSums::INTERVALS as $interval) {
            foreach (array_unique(array_merge(array_keys($a[$interval] ?? []), array_keys($b[$interval] ?? []))) as $column) {
                $keys = array_unique(array_merge(array_keys($a[$interval][$column] ?? []), array_keys($b[$interval][$column] ?? [])));
                sort($keys);
                foreach ($keys as $key) {
                    $result[$interval][$column][$key] = ($a[$interval][$column][$key] ?? 0) + ($b[$interval][$column][$key] ?? 0);
                }
            }
        }

        return $result;
    }
}
