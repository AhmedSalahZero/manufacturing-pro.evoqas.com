<?php

namespace App\Calculations;

// ══════════════════════════════════════════════════════════════════
//  Inventory statement: how much has to be made (or bought) each month.
//  The same routine the old application used for finished goods (quantities) and raw materials (values):
//    need this month  = this month's use + the use of the next "coverage" months (a fraction of a month counts pro rata)
//    made / bought    = max(0, need - opening balance)
//    closing balance  = opening + made - use
// ══════════════════════════════════════════════════════════════════

final class InventoryQuantityStatement
{
    /**
     * @param  array<int,float|int>  $use  month index => quantity (or value) used / sold
     * @return array{dates:array<int,int>,beginning_balance:array<int,float|int>,manufacturing_quantity:array<int,float|int>,total_quantity_available:array<int,float|int>,sales_quantity:array<int,float|int>,end_balance:array<int,float|int>}
     */
    public static function build(array $use, float|int $beginningBalance = 0, float|int $monthsToCover = 1): array
    {
        $statement = [
            'dates' => [], 'beginning_balance' => [], 'manufacturing_quantity' => [],
            'total_quantity_available' => [], 'sales_quantity' => [], 'end_balance' => [],
        ];

        $dates = array_keys($use);
        sort($dates);
        $count = count($dates);
        $balance = $beginningBalance;
        $wholeMonths = (int) floor($monthsToCover);

        for ($i = 0; $i < $count; $i++) {
            $date = $dates[$i];
            $current = $use[$date];

            $statement['beginning_balance'][$date] = $balance;
            $statement['dates'][$date] = $date;
            $statement['sales_quantity'][$date] = $current;

            $future = 0;
            $remaining = $monthsToCover;

            for ($j = 1; $j <= $wholeMonths && ($i + $j) < $count; $j++) {
                $future += $use[$dates[$i + $j]];
                $remaining -= 1;
            }

            if ($remaining > 0 && ($i + $wholeMonths + 1) < $count) {
                $future += $use[$dates[$i + $wholeMonths + 1]] * $remaining;
            }

            $made = max(0, $current + $future - $balance);
            $available = $balance + $made;
            $end = $available - $current;

            $statement['manufacturing_quantity'][$date] = $made;
            $statement['total_quantity_available'][$date] = $available;
            $statement['end_balance'][$date] = $end;

            $balance = $end;
        }

        return $statement;
    }
}
