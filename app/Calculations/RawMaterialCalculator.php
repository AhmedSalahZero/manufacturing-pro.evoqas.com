<?php

namespace App\Calculations;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — raw material purchases and supplier payments
//  Location: app/Calculations/RawMaterialCalculator.php
//
//  Pure calculation. Takes what all the products consume of one raw material (value per month) and says
//    · how much has to be bought each month (inventory statement: opening balance + coverage days),
//    · how it is paid to the supplier (the same collection-policy rules, with the material's own VAT / withholding),
//    · the withholding tax credit statement.
// ══════════════════════════════════════════════════════════════════

final class RawMaterialCalculator
{
    /**
     * @param  array<int,float|int>  $consumed   month index => value consumed by all products
     * @param  array<string,mixed>   $firstYearTerms   payment terms of the first operation year
     * @param  array<string,mixed>   $laterYearTerms   payment terms of the following years
     * @return array{inventory:array<string,array<int,float|int>>, payments:array<string,array<string,array<int,float|int>>>, withhold_credit:array<string,array<string,array<int,float|int>>>}
     */
    public static function calculate(
        Timeline $timeline,
        array $consumed,
        float $beginningInventoryValue,
        float $coverageDays,
        float $vatPercent,
        float $withholdPercent,
        array $firstYearTerms,
        array $laterYearTerms,
    ): array {
        $inventory = InventoryQuantityStatement::build($consumed, $beginningInventoryValue, $coverageDays / 30);
        $purchases = $inventory['manufacturing_quantity'];

        $payments = CollectionPolicy::statement($purchases, $vatPercent, $withholdPercent, $firstYearTerms, $laterYearTerms, $timeline);

        return [
            'inventory'       => $inventory,
            'payments'        => $payments,
            'withhold_credit' => self::withholdStatement($payments['monthly']['withhold_amount'] ?? [], 0.0, $timeline),
        ];
    }

    /**
     * Withholding tax credit: each month's withholding is added, and the balance of the previous month is settled
     * in January, April, July and October (the quarterly filing months).
     *
     * @param  array<int,float|int>  $withholds
     */
    public static function withholdStatement(array $withholds, float $openingBalance, Timeline $timeline): array
    {
        $result = [];

        foreach (IntervalSums::INTERVALS as $interval) {
            $series = $interval === 'monthly' ? $withholds : IntervalSums::sum($withholds, $interval, $timeline);
            $balance = $openingBalance;

            foreach ($series as $index => $withhold) {
                $due = $withhold + $balance;
                $settlement = 0;

                if (in_array($timeline->monthNumberOf($index), ['01', '04', '07', '10'], true)) {
                    $settlement = $result[$interval]['total_due'][$index - 1] ?? 0;
                }

                $end = $due - $settlement;

                $result[$interval]['beginning_balance'][$index] = $balance;
                $result[$interval]['withhold'][$index] = $withhold;
                $result[$interval]['total_due'][$index] = $due;
                $result[$interval]['payment'][$index] = $settlement;
                $result[$interval]['end_balance'][$index] = $end;

                $balance = $end;
            }
        }

        return $result;
    }
}
