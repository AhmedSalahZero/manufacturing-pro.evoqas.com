<?php

namespace App\Calculations;

use DateTimeImmutable;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — Loan calculator
//  Location: app/Calculations/LoanCalculator.php
//
//  Pure calculation, ported from the old application (CalculateLoanWithdrawal + CalculateFixedLoanAtEndService).
//  The loan of a fixed asset has two phases:
//    1. Drawdown: the asset is paid in months; what the owners' equity does not cover is drawn from the bank.
//       Interest on the drawn balance is added to the balance every month (it is "capitalised").
//    2. Repayment: from the last drawdown the loan (drawn balance + capitalised interest) is repaid.
//       Only interest is paid during the grace period; after it, equal instalments (monthly, quarterly or
//       semi-annually) that bring the balance to zero at the end of the tenor.
//  Interest uses the real number of days of each period over a 360-day year (days / 360 × rate).
//  Months are positions in the study's month list (0 = the first month); a month's date is the first of that month.
// ══════════════════════════════════════════════════════════════════

final class LoanCalculator
{
    /** Months in one instalment period: monthly 1, quarterly 3, semi-annually 6, annually 12 (the old spellings still work). */
    public static function intervalMonths(string $interval): int
    {
        return match ($interval) {
            'monthly'                          => 1,
            'quarterly', 'quartly'             => 3,
            'semi-annually', 'semi annually'   => 6,
            default                            => 12,
        };
    }

    /** The first-of-month date, $months after $date. */
    public static function addMonths(string $date, int $months): string
    {
        return (new DateTimeImmutable(substr($date, 0, 10)))->modify('first day of this month')->modify(($months >= 0 ? '+' : '').$months.' months')->format('Y-m-d');
    }

    public static function daysBetween(string $later, string $earlier): int
    {
        return (int) round((strtotime($later.' 00:00:00') - strtotime($earlier.' 00:00:00')) / 86400);
    }

    /**
     * Phase 1. What is drawn from the bank, month by month, with the interest added to the balance.
     *
     * @param  array<int,float|int>  $withdrawals  month index => amount drawn (every month from the first to the last draw)
     * @param  float                 $ratePct      yearly interest in %
     * @param  string                $firstDate    "Y-m-01" of month index 0
     * @return array{interest:array<int,float>, end_balance:array<int,float>, withdrawal:array<int,float|int>, start_index:?int, amount:float}
     */
    public static function drawdown(array $withdrawals, float $ratePct, string $firstDate): array
    {
        $pricing = $ratePct / 100;
        $interest = [];
        $endBalance = [];
        $previousIndex = null;
        $previousEnd = 0.0;
        $lastNonZero = null;

        foreach ($withdrawals as $index => $drawn) {
            $days = $previousIndex === null ? 0 : self::daysBetween(self::addMonths($firstDate, (int) $index), self::addMonths($firstDate, (int) $previousIndex));
            $factor = ($pricing / 360) * $days;
            $beginning = $previousIndex === null ? (float) $drawn : $previousEnd + $drawn;
            $interest[$index] = $beginning * $factor;
            $end = $beginning + $interest[$index];
            $end = ($end < 1 && $end > -1) ? 0.0 : $end;
            $endBalance[$index] = $end;
            $previousEnd = $end;
            $previousIndex = $index;

            if ($drawn != 0) {
                $lastNonZero = (int) $index;
            }
        }

        return [
            'interest'    => $interest,
            'end_balance' => $endBalance,
            'withdrawal'  => $withdrawals,
            'start_index' => $lastNonZero,
            'amount'      => $endBalance === [] ? 0.0 : (float) end($endBalance),
        ];
    }

    /**
     * Phase 2. The repayment schedule.
     *
     * @param  int     $startIndex  month index the loan starts (the last drawdown month)
     * @param  string  $startDate   "Y-m-01" of that month
     * @return array<string,array<int,float>>  beginning, interestAmount, schedulePayment, principleAmount, endBalance (by month index, instalment dates only), plus 'totals'
     */
    public static function schedule(int $startIndex, string $startDate, float $amount, float $ratePct, int $tenor, string $interval, int $grace): array
    {
        if ($amount <= 0 || $tenor < 1) {
            return [];
        }

        $step = self::intervalMonths($interval);
        $pricing = $ratePct / 100;

        // The dates of the schedule: the start and then one per instalment period.
        $dates = [];
        $index = $startIndex;
        for ($i = 0; $i <= $tenor / $step; $i++) {
            $dates[$index] = self::addMonths($startDate, $i * $step);
            $index += $step;
        }

        $days = [];
        $first = true;
        foreach ($dates as $index => $date) {
            $days[$index] = $first ? 0 : self::daysBetween($date, $dates[$index - $step]);
            $first = false;
        }

        // The first instalment falls one period after the grace period (the old code counted whole periods).
        $keys = array_keys($dates);
        $installmentStart = $keys[intdiv($grace + $step, $step)] ?? null;
        $endIndex = $keys[array_key_last($keys)];

        // Factors that let the equal instalment be worked out in one go.
        $loanFactor = $amount;
        $installmentFactor = 0.0;
        $loanFactors = [];
        $installmentFactors = [];
        $interestFactors = [];
        foreach ($dates as $index => $date) {
            $interestFactors[$index] = ($pricing / 360) * $days[$index];
            $loanFactor = ($installmentStart === null || $index < $installmentStart) ? $amount : $loanFactor * (1 + $interestFactors[$index]);
            $loanFactors[$index] = $loanFactor;

            if ($installmentStart === null || $index < $installmentStart) {
                $installmentFactor = 0.0;
            } elseif ($index === $installmentStart) {
                $installmentFactor = -1.0;
            } else {
                $installmentFactor = $installmentFactor * (1 + $interestFactors[$index]) - 1;
            }
            $installmentFactors[$index] = $installmentFactor;
        }

        $installments = [];
        if ($installmentStart !== null && $installmentFactors[$endIndex] != 0.0) {
            $installment = $loanFactors[$endIndex] / ($installmentFactors[$endIndex] * -1);
            for ($pointer = $installmentStart; $pointer <= $endIndex; $pointer += $step) {
                $installments[$pointer] = $installment;
            }
        }

        $result = ['beginning' => [], 'interestAmount' => [], 'schedulePayment' => [], 'principleAmount' => [], 'endBalance' => []];
        $totals = ['totalSchedulePayment' => 0.0, 'totalPrincipleAmount' => 0.0, 'totalInterestAmount' => 0.0];
        $first = true;

        foreach ($dates as $index => $date) {
            $beginning = $first ? $amount : ($result['endBalance'][$index - $step] ?? 0.0);
            $interest = $beginning * $interestFactors[$index];
            $instalment = $installments[$index] ?? 0.0;
            // Without capitalisation the interest is paid while no instalment is due.
            $payment = $instalment == 0.0 ? $interest : $instalment;
            $end = $beginning + $interest - $payment;
            $end = ($end < 1 && $end > -1) ? 0.0 : $end;

            $result['beginning'][$index] = $beginning;
            $result['interestAmount'][$index] = $interest;
            $result['schedulePayment'][$index] = $payment;
            $result['principleAmount'][$index] = $payment - $interest;
            $result['endBalance'][$index] = $end;
            $totals['totalInterestAmount'] += $interest;
            $totals['totalSchedulePayment'] += $payment;
            $totals['totalPrincipleAmount'] += $payment - $interest;
            $first = false;
        }

        $result['totals'] = $totals;

        return $result;
    }

    /**
     * A schedule's end balance for every month: the balance stays as it was between instalment dates.
     *
     * @param  array<int,float>  $endBalance
     * @param  list<int>         $months
     * @return array<int,float>
     */
    public static function fillMonths(array $endBalance, array $months): array
    {
        $filled = [];
        $previous = 0.0;

        foreach ($months as $month) {
            if (isset($endBalance[$month])) {
                $previous = $endBalance[$month];
            }
            $filled[$month] = $previous;
        }

        return $filled;
    }
}
