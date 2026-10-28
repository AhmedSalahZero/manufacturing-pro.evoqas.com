<?php

namespace App\Calculations;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — Cost of goods sold and stock value of a product
//  Location: app/Calculations/FgCostingCalculator.php
//
//  Pure calculation, ported from the old application (Project::recalculateFgInventoryValueStatement).
//  Everything spent on making a product in a month (raw materials, direct labour, overheads and the depreciation of the
//  machines) is added to what was already in stock. Each kind of cost is then shared out over the units available
//  (weighted average): the units SOLD take their part (the cost of goods sold) and the units still in stock keep theirs.
//      available value  = value in stock at the start + spent this month
//      cost per unit    = available value / units available            (0 when there are no units)
//      cost of goods sold = cost per unit x units sold
//      value in stock at the end = available value - cost of goods sold
//  The depreciation is followed the same way, but only to know how much of it was sold: it is not added to the stock
//  value and not to the cost of goods sold, because the overheads already contain it; the income statement adds it back
//  to show EBITDA.
// ══════════════════════════════════════════════════════════════════

final class FgCostingCalculator
{
    public const RAW_MATERIAL = 'raw_material_value';
    public const DIRECT_LABOR = 'direct_labor_value';
    public const OVERHEADS = 'manufacturing_overheads_value';
    public const DEPRECIATION = 'product_depreciation_allocation';

    public const TYPES = [self::RAW_MATERIAL, self::DIRECT_LABOR, self::OVERHEADS, self::DEPRECIATION];

    /**
     * @param  array<int,float|int>  $unitsAvailable  month index => units that could be sold (stock at the start + made)
     * @param  array<int,float|int>  $unitsSold       month index => units sold
     * @param  array<string,float>   $openingValue    kind of cost => value of the finished goods in stock on day one (depreciation is always 0)
     * @param  array<string,array<int,float|int>>  $spent  kind of cost => month index => spent that month
     * @return array{statements:array<string,array<string,array<int,float|int>>>, inventory:array{cogs:array<int,float|int>, end_balance:array<int,float|int>}}
     */
    public static function calculate(array $unitsAvailable, array $unitsSold, array $openingValue, array $spent): array
    {
        $statements = [];
        $cogs = [];
        $endBalance = [];

        foreach (self::TYPES as $type) {
            $series = (array) ($spent[$type] ?? []);
            ksort($series);
            $balance = $type === self::DEPRECIATION ? 0.0 : (float) ($openingValue[$type] ?? 0);
            $statement = [];

            foreach ($series as $month => $value) {
                $available = $value + $balance;
                $units = $unitsAvailable[$month] ?? 0;
                $perUnit = $units ? $available / $units : 0;
                $sold = $perUnit * ($unitsSold[$month] ?? 0);
                $end = $available - $sold;

                $statement['beginning_value'][$month] = $balance;
                $statement['total_available_manufacturing_expenses'][$month] = $available;
                $statement['addition_manufacturing_expenses'][$month] = $value;
                $statement['manufacturing_expenses_per_unit'][$month] = $perUnit;
                $statement['cogs'][$month] = $sold;
                $statement['end_balance'][$month] = $end;

                $balance = $end;

                if ($type !== self::DEPRECIATION) {
                    $cogs[$month] = ($cogs[$month] ?? 0) + $sold;
                    $endBalance[$month] = ($endBalance[$month] ?? 0) + $end;
                }
            }

            $statements[$type] = $statement;
        }

        return ['statements' => $statements, 'inventory' => ['cogs' => $cogs, 'end_balance' => $endBalance]];
    }
}
