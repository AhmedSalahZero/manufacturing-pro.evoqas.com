<?php

namespace App\Calculations;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — product sales calculation
//  Location: app/Calculations/ProductSalesCalculator.php
//
//  Pure calculation (no database): turns what the user typed for a product into monthly figures.
//
//  For every operation month:
//    quantity = the year's quantity x the month's seasonality share      (local and export separately)
//    value    = quantity x the year's price per unit
//  Then:
//    · the finished-goods inventory statement says how many units must be made each month;
//    · every raw material consumed = recipe % x the year's average price per unit sold x units made.
//      (The previous application used the month's sales value / units sold, which is the same in every month with sales
//       but gave zero for units made in a month with no sales yet; the year's price fixes that.)
//
//  Input arrays are keyed by YEAR INDEX (0 = the first calendar year of the study).
//  The result is keyed by MONTH INDEX, like every monthly series in the application.
// ══════════════════════════════════════════════════════════════════

final class ProductSalesCalculator
{
    /**
     * @param  array{
     *     selling_start_index:int,
     *     seasonality_weights:array<string,float>,
     *     local_quantities:array<int,float|int|string>, local_prices:array<int,float|int|string>,
     *     export_quantities:array<int,float|int|string>, export_prices:array<int,float|int|string>,
     *     fg_beginning_quantity:float|int|string, fg_coverage_days:float|int|string,
     *     recipes?:array<int,array<int,float|int|string>>,
     *     sensitivity_price_rate?:float|int|string|null
     * }  $input   recipes: raw material id => year index => % of sales value
     * @return array{
     *     local_values:array<int,float>, export_values:array<int,float>,
     *     local_quantities:array<int,float>, export_quantities:array<int,float>,
     *     values:array<int,float>, quantities:array<int,float>,
     *     inventory:array<string,array<int,float|int>>,
     *     raw_material_consumed:array<int|string,array<int,float>>
     * }
     */
    public static function calculate(Timeline $timeline, array $input, bool $sensitivity = false): array
    {
        $shares = SeasonalityCalculator::monthlyShares($input['seasonality_weights'], $timeline, (int) $input['selling_start_index']);
        $priceFactor = $sensitivity ? 1 + ((float) ($input['sensitivity_price_rate'] ?? 0)) / 100 : 1;

        $localQuantities = $localValues = $exportQuantities = $exportValues = [];

        foreach ($shares as $month => $share) {
            $year = $timeline->yearIndexOf($month);

            $localQuantity = $share * (float) ($input['local_quantities'][$year] ?? 0);
            $localQuantities[$month] = $localQuantity;
            $localValues[$month] = $localQuantity * (float) ($input['local_prices'][$year] ?? 0) * $priceFactor;

            $exportQuantity = $share * (float) ($input['export_quantities'][$year] ?? 0);
            $exportQuantities[$month] = $exportQuantity;
            $exportValues[$month] = $exportQuantity * (float) ($input['export_prices'][$year] ?? 0) * $priceFactor;
        }

        $quantities = Series::sum([$localQuantities, $exportQuantities]);
        $values = Series::sum([$localValues, $exportValues]);

        $inventory = InventoryQuantityStatement::build(
            $quantities,
            (float) $input['fg_beginning_quantity'],
            ((float) $input['fg_coverage_days']) / 30,
        );

        $consumed = [];

        foreach ($values as $month => $value) {
            $year = $timeline->yearIndexOf($month);
            $made = $inventory['manufacturing_quantity'][$month] ?? 1;

            // Average price of a unit in this year (local and export together), the same price the sales of the year use.
            $yearUnits = (float) ($input['local_quantities'][$year] ?? 0) + (float) ($input['export_quantities'][$year] ?? 0);
            $unitPrice = $yearUnits > 0
                ? ((float) ($input['local_quantities'][$year] ?? 0) * (float) ($input['local_prices'][$year] ?? 0)
                    + (float) ($input['export_quantities'][$year] ?? 0) * (float) ($input['export_prices'][$year] ?? 0)) / $yearUnits * $priceFactor
                : 0.0;

            foreach ($input['recipes'] ?? [] as $rawMaterialId => $percentages) {
                $percent = ((float) ($percentages[$year] ?? 0)) / 100;
                $amount = $percent * $unitPrice * $made;

                $consumed[$rawMaterialId][$month] = $amount;
                $consumed['total'][$month] = ($consumed['total'][$month] ?? 0) + $amount;
            }
        }

        return [
            'local_values'          => $localValues,
            'export_values'         => $exportValues,
            'local_quantities'      => $localQuantities,
            'export_quantities'     => $exportQuantities,
            'values'                => $values,
            'quantities'            => $quantities,
            'inventory'             => $inventory,
            'raw_material_consumed' => $consumed,
        ];
    }

    /**
     * The yearly figures the screen shows: the year's local / export quantities from the capacity, and the price chain.
     * (What the old page's JavaScript did when you typed a number.)
     *
     * @param  array<int,float|int|string>  $capacity            year index => units the factory can make
     * @param  array<int,float|int|string>  $targetPercent       year index => % of the capacity that is sold
     * @param  array<int,float|int|string>  $localSharePercent   year index => % of the quantity sold locally (the rest is export)
     * @return array{target:array<int,float>, local:array<int,float>, export:array<int,float>}
     */
    public static function yearlyQuantities(array $capacity, array $targetPercent, array $localSharePercent): array
    {
        $target = $local = $export = [];

        foreach ($capacity as $year => $units) {
            $target[$year] = (float) $units * ((float) ($targetPercent[$year] ?? 0)) / 100;
            $local[$year] = $target[$year] * ((float) ($localSharePercent[$year] ?? 0)) / 100;
            $export[$year] = $target[$year] - $local[$year];
        }

        return compact('target', 'local', 'export');
    }

    /**
     * The price per unit year by year: the first year's price grown by that year's growth rate, each from the year before,
     * rounded to a whole number like the old screen did.
     *
     * @param  array<int,float|int|string>  $growthPercent  year index => % growth over the previous year (the first year's rate is not used)
     * @return array<int,float>
     */
    public static function priceChain(float $firstPrice, array $growthPercent, int $years): array
    {
        $prices = [0 => $firstPrice];

        for ($year = 1; $year < $years; $year++) {
            $prices[$year] = round($prices[$year - 1] * (1 + ((float) ($growthPercent[$year] ?? 0)) / 100));
        }

        return $prices;
    }
}
