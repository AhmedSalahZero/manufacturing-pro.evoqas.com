<?php

namespace App\Calculations;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — sales segments (sales channels and export markets)
//  Location: app/Calculations/SegmentCalculator.php
//
//  Pure calculation (no database). Everything here is keyed by YEAR INDEX (0 = the calendar year the study starts in).
//  For product p, year y and segment s (a sales channel for local sales, an export market for export sales):
//
//    segment quantity            = group quantity of the year x the segment's share % of that year          (R6.2)
//    local price of a channel    = base local price of the year x (1 + the channel's price difference %)    (R6.3)
//    foreign price of a market   = first price, then each year the year before x (1 + growth %), 2 decimals (R3.2)
//    export price (study money)  = foreign price of the year x the FX rate of the year                      (R6.4)
//    average price of the year   = total value of the year / total quantity of the year                     (R6.7)
//
//  Nothing is rounded to a whole number here: the base local price chain keeps today's rounding (ProductSalesCalculator::priceChain),
//  foreign prices round to 2 decimals, values are worked out at full precision (R6.11).
// ══════════════════════════════════════════════════════════════════

final class SegmentCalculator
{
    /**
     * @param  array<int,float|int|string>  $groupQuantities  year index => the local (or export) quantity of the product
     * @param  array<int,float|int|string>  $allocation       year index => the segment's share, in %
     * @return array<int,float>
     */
    public static function quantities(array $groupQuantities, array $allocation): array
    {
        $result = [];

        foreach ($groupQuantities as $year => $quantity) {
            $result[$year] = (float) $quantity * ((float) ($allocation[$year] ?? 0)) / 100;
        }

        return $result;
    }

    /**
     * @param  array<int,float|int|string>  $basePrices   year index => base local price
     * @param  array<int,float|int|string>  $differences  year index => difference from the base price, in %
     * @return array<int,float>
     */
    public static function localPrices(array $basePrices, array $differences): array
    {
        $result = [];

        foreach ($basePrices as $year => $price) {
            $result[$year] = (float) $price * (1 + ((float) ($differences[$year] ?? 0)) / 100);
        }

        return $result;
    }

    /**
     * The price in the market's currency, year by year: the typed first price, then each year the year before grown by that year's
     * growth rate, rounded to 2 decimals (D4).
     *
     * @param  list<int>                    $years   the year indexes of the product, in order (the first one carries the typed price)
     * @param  array<int,float|int|string>  $growth  year index => % growth over the year before (the first year's rate is not used)
     * @return array<int,float>
     */
    public static function foreignPrices(float $firstPrice, array $growth, array $years): array
    {
        $result = [];
        $previous = null;

        foreach ($years as $year) {
            $previous = $previous === null
                ? $firstPrice
                : round($previous * (1 + ((float) ($growth[$year] ?? 0)) / 100), 2);
            $result[$year] = $previous;
        }

        return $result;
    }

    /**
     * Price in the study currency = price in the foreign currency x the FX rate of the same year.
     *
     * @param  array<int,float|int|string>  $foreignPrices  year index => price in the market's currency
     * @param  array<int,float|int|string>  $fxRates        year index => units of the study currency per 1 foreign unit
     * @return array<int,float>
     */
    public static function exportPrices(array $foreignPrices, array $fxRates): array
    {
        $result = [];

        foreach ($foreignPrices as $year => $price) {
            $result[$year] = (float) $price * (float) ($fxRates[$year] ?? 0);
        }

        return $result;
    }

    /**
     * The figures are kept for one year more than the study shows (the extended end year, used by the terminal value):
     * the extra year repeats the last one. Same rule as the product form's saved figures.
     *
     * @param  array<int,mixed>  $byYear
     * @return array<int,mixed>
     */
    public static function extend(array $byYear): array
    {
        if ($byYear !== []) {
            $last = array_key_last($byYear);
            $byYear[$last + 1] = $byYear[$last];
        }

        return $byYear;
    }

    /**
     * Average selling price of a year in the study currency: total value / total quantity of all the segments.
     * Zero when nothing is sold that year.
     *
     * @param  iterable<array{quantities:array<int,float|int|string>, prices:array<int,float|int|string>}>  $segments
     */
    public static function averagePrice(iterable $segments, int $year): float
    {
        $units = 0.0;
        $value = 0.0;

        foreach ($segments as $segment) {
            $quantity = (float) ($segment['quantities'][$year] ?? 0);
            $units += $quantity;
            $value += $quantity * (float) ($segment['prices'][$year] ?? 0);
        }

        return $units > 0 ? $value / $units : 0.0;
    }

    /**
     * Total value of a year: the sum of quantity x price of every segment.
     *
     * @param  iterable<array{quantities:array<int,float|int|string>, prices:array<int,float|int|string>}>  $segments
     */
    public static function totalValue(iterable $segments, int $year): float
    {
        $value = 0.0;

        foreach ($segments as $segment) {
            $value += (float) ($segment['quantities'][$year] ?? 0) * (float) ($segment['prices'][$year] ?? 0);
        }

        return $value;
    }
}
