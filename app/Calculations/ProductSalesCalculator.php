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
//  Sales channels (local) and export markets (export), each with its own quantity and price, are added up; the local
//  sales follow the local pattern and the export sales the export pattern (see SegmentCalculator).
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
     *     export_seasonality_weights?:array<string,float>,
     *     local_quantities:array<int,float|int|string>, local_prices:array<int,float|int|string>,
     *     export_quantities:array<int,float|int|string>, export_prices:array<int,float|int|string>,
     *     local_segments?:array<int|string,array{quantities:array<int,float|int|string>,prices:array<int,float|int|string>}>,
     *     export_segments?:array<int|string,array{quantities:array<int,float|int|string>,prices:array<int,float|int|string>}>,
     *     fg_beginning_quantity:float|int|string, fg_coverage_days:float|int|string,
     *     recipes?:array<int,array<int,float|int|string>>,
     *     sensitivity_price_rate?:float|int|string|null
     * }  $input   recipes: raw material id => year index => % of sales value.
     *             Segments (sales channels / export markets): segment id => yearly quantity and price in the STUDY currency.
     *             A product without segments behaves as one channel "local" (local_quantities x local_prices) and one market
     *             "export" (export_quantities x export_prices) - the way every study worked before channels and markets existed.
     *             The export pattern defaults to the local one.
     * @return array{
     *     local_values:array<int,float>, export_values:array<int,float>,
     *     local_quantities:array<int,float>, export_quantities:array<int,float>,
     *     values:array<int,float>, quantities:array<int,float>,
     *     segments:array{local:array<int|string,array{quantities:array<int,float>,values:array<int,float>}>, export:array<int|string,array{quantities:array<int,float>,values:array<int,float>}>},
     *     average_prices:array<int,float>,
     *     inventory:array<string,array<int,float|int>>,
     *     raw_material_consumed:array<int|string,array<int,float>>
     * }
     */
    public static function calculate(Timeline $timeline, array $input, bool $sensitivity = false): array
    {
        $localWeights = $input['seasonality_weights'];
        $exportWeights = $input['export_seasonality_weights'] ?? $localWeights;
        $localShares = SeasonalityCalculator::monthlyShares($localWeights, $timeline, (int) $input['selling_start_index']);
        $exportShares = $exportWeights === $localWeights
            ? $localShares
            : SeasonalityCalculator::monthlyShares($exportWeights, $timeline, (int) $input['selling_start_index']);
        $priceFactor = $sensitivity ? 1 + ((float) ($input['sensitivity_price_rate'] ?? 0)) / 100 : 1;

        $localSegments = $input['local_segments'] ?? ['local' => ['quantities' => $input['local_quantities'], 'prices' => $input['local_prices']]];
        $exportSegments = $input['export_segments'] ?? ['export' => ['quantities' => $input['export_quantities'], 'prices' => $input['export_prices']]];

        // Every channel follows the local pattern, every market the export pattern (R4.2).
        $local = self::segmentMonths($timeline, $localSegments, $localShares, $priceFactor);
        $export = self::segmentMonths($timeline, $exportSegments, $exportShares, $priceFactor);

        $localQuantities = self::monthTotals($local, 'quantities', array_keys($localShares));
        $localValues = self::monthTotals($local, 'values', array_keys($localShares));
        $exportQuantities = self::monthTotals($export, 'quantities', array_keys($exportShares));
        $exportValues = self::monthTotals($export, 'values', array_keys($exportShares));

        $quantities = Series::sum([$localQuantities, $exportQuantities]);
        $values = Series::sum([$localValues, $exportValues]);

        // The units to make follow the sum of the local and export monthly quantities (R4.5).
        $inventory = InventoryQuantityStatement::build(
            $quantities,
            (float) $input['fg_beginning_quantity'],
            ((float) $input['fg_coverage_days']) / 30,
        );

        $consumed = [];
        $averagePrices = [];
        $allSegments = array_merge(array_values($localSegments), array_values($exportSegments));

        foreach ($values as $month => $value) {
            $year = $timeline->yearIndexOf($month);
            $made = $inventory['manufacturing_quantity'][$month] ?? 1;

            // Average price of a unit in this year (every channel and market together), the same price the sales of the year use (R6.7).
            $averagePrices[$year] ??= SegmentCalculator::averagePrice($allSegments, $year) * $priceFactor;
            $unitPrice = $averagePrices[$year];

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
            'segments'              => ['local' => $local, 'export' => $export],
            'average_prices'        => $averagePrices,
            'inventory'             => $inventory,
            'raw_material_consumed' => $consumed,
        ];
    }

    /**
     * Monthly quantity and value of every segment: quantity = the year's segment quantity x the month's share of the pattern;
     * value = quantity x the year's price (x the price what-if, when there is one).
     *
     * @param  array<int|string,array{quantities:array<int,float|int|string>,prices:array<int,float|int|string>}>  $segments
     * @param  array<int,float>  $shares  month index => share of the year
     * @return array<int|string,array{quantities:array<int,float>,values:array<int,float>}>
     */
    private static function segmentMonths(Timeline $timeline, array $segments, array $shares, float|int $priceFactor): array
    {
        $result = [];

        foreach ($segments as $key => $segment) {
            $quantities = $values = [];

            foreach ($shares as $month => $share) {
                $year = $timeline->yearIndexOf($month);
                $quantity = $share * (float) ($segment['quantities'][$year] ?? 0);

                $quantities[$month] = $quantity;
                $values[$month] = $quantity * (float) ($segment['prices'][$year] ?? 0) * $priceFactor;
            }

            $result[$key] = ['quantities' => $quantities, 'values' => $values];
        }

        return $result;
    }

    /**
     * The sum of one column over all segments, for every month (a group with no segment is zero).
     *
     * @param  array<int|string,array<string,array<int,float>>>  $segments
     * @param  list<int>  $months
     * @return array<int,float>
     */
    private static function monthTotals(array $segments, string $column, array $months): array
    {
        return Series::sum(array_column($segments, $column), $months);
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
