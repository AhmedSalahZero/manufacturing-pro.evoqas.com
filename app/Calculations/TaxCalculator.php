<?php

namespace App\Calculations;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — Value added tax and corporate tax (Egyptian rules)
//  Location: app/Calculations/TaxCalculator.php
//
//  Pure calculation, ported from the old application (Project::recalculateVatStatements,
//  HasCollectionOrPaymentStatement::calculateVatStatement / calculateCorporateTaxesStatement, Project::calculateIncomeStatement).
//
//  VAT       Each month: VAT charged on sales  -  VAT paid on purchases.  The balance is paid the month AFTER.
//            When purchases carry more VAT than sales the month is a credit: it is not paid and not refunded, it is carried
//            forward and set against the VAT of the next months (a negative balance).
//  Corporate tax
//            The tax of a calendar year is booked in the last month of that year. The tax that customers withheld during
//            the year (the "debit withholding") builds up all the time and is set against it. What is still due after the
//            year end is paid in APRIL of the next year. When the withheld amount is more than the tax, nothing is paid
//            and the difference stays on the books as a credit.
// ══════════════════════════════════════════════════════════════════

final class TaxCalculator
{
    /** Tax on the profit of every month that has a profit; a month with a loss gives no tax (the old application). */
    public const BASIS_MONTHLY_PROFIT = 'monthly_profit';

    /** Tax on the profit of the whole calendar year; a year with a loss gives no tax. */
    public const BASIS_YEAR = 'year';

    /**
     * The monthly VAT statement.
     *
     * @param  array<int,float|int>  $additions  month index => VAT on sales minus VAT on purchases
     * @param  list<int>             $months     month indexes in order
     * @return array<string,array<int,float|int>>  columns beginning_balance, addition, total_due, payment, end_balance
     */
    public static function vatStatement(array $additions, float $opening, array $months): array
    {
        $statement = ['beginning_balance' => [], 'addition' => [], 'total_due' => [], 'payment' => [], 'end_balance' => []];
        $balance = $opening;
        $payment = 0.0;

        foreach ($months as $month) {
            $addition = $additions[$month] ?? 0;
            $due = $addition + $balance;
            $end = $due - $payment;

            $statement['beginning_balance'][$month] = $balance;
            $statement['addition'][$month] = $addition;
            $statement['total_due'][$month] = $due;
            $statement['payment'][$month] = $payment;
            $statement['end_balance'][$month] = $end;

            $balance = $end;
            // Next month the whole balance is paid; a credit (zero or less) is carried forward, never paid out.
            $payment = $due <= 0 ? 0.0 : max(0.0, $end);
        }

        return $statement;
    }

    /**
     * The corporate tax of each calendar year, booked in the last month of the year.
     *
     * @param  array<int,float|int>        $profit    month index => profit before tax of the month
     * @param  array<int,list<int>>        $years     year index => its month indexes in order
     * @param  array<int,int>              $bookedAt  year index => month index where the year's tax is booked
     * @return array<int,float>                       month index (where booked) => tax
     */
    public static function corporateTax(array $profit, array $years, array $bookedAt, float $ratePercent, string $basis): array
    {
        $rate = $ratePercent / 100;
        $tax = [];

        foreach ($years as $year => $months) {
            if (! isset($bookedAt[$year])) {
                continue;
            }

            $amount = 0.0;
            if ($basis === self::BASIS_YEAR) {
                $amount = max(0.0, array_sum(array_map(fn ($m) => $profit[$m] ?? 0, $months))) * $rate;
            } else {
                foreach ($months as $month) {
                    $value = $profit[$month] ?? 0;
                    $amount += $value < 0 ? 0 : $value * $rate;
                }
            }

            $tax[$bookedAt[$year]] = $amount;
        }

        return $tax;
    }

    /**
     * The monthly corporate tax statement.
     *
     * @param  array<int,float|int>  $withheld        month index => tax that customers withheld that month
     * @param  array<int,float|int>  $tax             month index => tax booked (the year-end months)
     * @param  array<int,int>        $paidIn          month index of a year end => month index where it is paid (April)
     * @param  list<int>             $months          month indexes in order
     * @param  int|null              $openingPaidIn   month where the tax payable on day one is paid on its own (null: it is paid with the first year-end)
     * @return array<string,array<int,float|int>>     columns beginning_balance, addition, total_due, payment, end_balance
     */
    public static function corporateTaxStatement(array $withheld, array $tax, float $opening, array $months, array $paidIn, ?int $openingPaidIn): array
    {
        $statement = ['beginning_balance' => [], 'addition' => [], 'total_due' => [], 'payment' => [], 'end_balance' => []];
        $settlements = [];
        $balance = $opening;

        if ($openingPaidIn !== null && $opening != 0) {
            $settlements[$openingPaidIn] = $opening;
        }

        foreach ($months as $month) {
            $addition = $withheld[$month] ?? 0;
            $due = $balance - $addition + ($tax[$month] ?? 0);

            if (isset($paidIn[$month])) {
                // What is due at the year end is paid later; a credit (less than zero) is not paid.
                $settlements[$paidIn[$month]] = ($settlements[$paidIn[$month]] ?? 0) + max(0, $due);
            }

            $payment = $settlements[$month] ?? 0;
            $end = $due - $payment;

            $statement['beginning_balance'][$month] = $balance;
            $statement['addition'][$month] = $addition;
            $statement['total_due'][$month] = $due;
            $statement['payment'][$month] = $payment;
            $statement['end_balance'][$month] = $end;

            $balance = $end;
        }

        return $statement;
    }

    /** The month index of the April in the calendar year after the given month, or null when the timeline does not reach it. */
    public static function aprilOfNextYear(Timeline $timeline, int $month): ?int
    {
        $year = (int) substr($timeline->dates[$month], 0, 4) + 1;

        foreach ($timeline->dates as $index => $date) {
            if (substr($date, 0, 7) === sprintf('%d-04', $year)) {
                return $index;
            }
        }

        return null;
    }

    /** The first April of the study (null when the study starts after April and so has none in its first calendar year). */
    public static function firstAprilOfFirstYear(Timeline $timeline): ?int
    {
        $firstYear = substr((string) reset($timeline->dates), 0, 4);

        foreach ($timeline->dates as $index => $date) {
            if (substr($date, 0, 7) === $firstYear.'-04') {
                return $index;
            }
        }

        return null;
    }

    /** Year-end month => the April of the next calendar year, for every calendar year of the timeline (the old application's way). @return array<int,int> */
    public static function aprilAfterYearEnd(Timeline $timeline): array
    {
        $indexOfLabel = [];
        $yearEnd = [];

        foreach ($timeline->dates as $index => $date) {
            $indexOfLabel[substr($date, 0, 7)] = $index;
            $yearEnd[(int) substr($date, 0, 4)] = $index;   // the last month of each calendar year
        }

        $result = [];
        foreach ($yearEnd as $year => $index) {
            $april = $indexOfLabel[sprintf('%d-04', $year + 1)] ?? null;
            if ($april !== null) {
                $result[$index] = $april;
            }
        }

        return $result;
    }
}
