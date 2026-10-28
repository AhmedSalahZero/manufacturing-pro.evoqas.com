<?php

namespace App\Calculations;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — Staff cost of one position
//  Location: app/Calculations/ManpowerCalculator.php
//
//  Rules kept from the old application (HasManpowerExpense::calculateManpowerResult):
//    · the months are the operation months (from the operation start to the end of the extended study);
//    · headcount of a month = headcount of the month before (the existing count in the first month) + people hired that month;
//    · the NET monthly salary grows by the yearly increase % every 12 operation months (not on the calendar year);
//    · salary payments  = net salary x headcount;
//    · salary expense   = net salary x headcount / (1 - (income tax % + social insurance %))  (the employer's total cost);
//    · tax and social insurance = expense x (tax % + insurance %), paid one month later (30 days), statement as for any payable.
// ══════════════════════════════════════════════════════════════════

final class ManpowerCalculator
{
    /**
     * @param  array<int,float|int>  $hirings  month index => people hired in that month
     * @return array{accumulated_manpower_counts:array<int,float|int>, salary_payments:array<int,float|int>, salary_expenses:array<int,float|int>, tax_and_social_insurance_statement:array<string,array<string,array<int,float|int>>>}
     */
    public static function calculate(
        Timeline $timeline,
        float $existingCount,
        array $hirings,
        float $monthlyNetSalary,
        float $annualIncreasePercent,
        float $taxPercent,
        float $insurancePercent,
    ): array {
        $levy = ($taxPercent + $insurancePercent) / 100;
        $counts = [];
        $payments = [];
        $expenses = [];
        $salary = $monthlyNetSalary;
        $position = 0;

        foreach (array_keys($timeline->operationMonths) as $month) {
            $counts[$month] = ($counts[$month - 1] ?? $existingCount) + (float) ($hirings[$month] ?? 0);

            if ($position !== 0 && $position % 12 === 0) {
                $salary *= 1 + $annualIncreasePercent / 100;
            }

            $payments[$month] = $salary * $counts[$month];
            $expenses[$month] = $levy >= 1 ? 0 : $salary * $counts[$month] / (1 - $levy);
            $position++;
        }

        $levies = Series::scale($expenses, $levy);
        $paid = CollectionPolicy::apply([30 => 100.0], $levies);

        return [
            'accumulated_manpower_counts'        => $counts,
            'salary_payments'                    => $payments,
            'salary_expenses'                    => $expenses,
            'tax_and_social_insurance_statement' => CollectionPolicy::buildStatement($levies, [], $paid, [], $timeline),
        ];
    }
}
