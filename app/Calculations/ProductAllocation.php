<?php

namespace App\Calculations;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — Splitting a cost between products
//  Location: app/Calculations/ProductAllocation.php
//
//  A cost (a salary type, an expense) is split between the products either
//    · by revenue: each product takes its share of the month's total sales value, or
//    · by percentages typed by the person: every product takes its percentage, shared out each month only between the
//      products that have started selling (so the shares of the month always add up to 1).
// ══════════════════════════════════════════════════════════════════

final class ProductAllocation
{
    /**
     * Each product's share of the month's sales value.
     *
     * @param  array<int,array<int,float|int>>  $salesValuesByProduct  product id => month index => sales value
     * @return array<int,array<int,float>>                              product id => month index => share (0..1)
     */
    public static function revenueShares(array $salesValuesByProduct): array
    {
        $totals = [];
        foreach ($salesValuesByProduct as $series) {
            foreach ($series as $month => $value) {
                $totals[$month] = ($totals[$month] ?? 0) + $value;
            }
        }

        $shares = [];
        foreach ($salesValuesByProduct as $productId => $series) {
            foreach ($series as $month => $value) {
                $shares[$productId][$month] = $totals[$month] ? $value / $totals[$month] : 0;
            }
        }

        return $shares;
    }

    /**
     * Typed percentages turned into monthly shares.
     *
     * @param  array<int,float|int>  $percentByProduct   product id => percentage
     * @param  array<int,int>        $sellingStartByProduct product id => month index the product starts selling
     * @param  list<int>             $months             the month indexes to work out
     * @return array<int,array<int,float>>               product id => month index => share (0..1)
     */
    public static function typedShares(array $percentByProduct, array $sellingStartByProduct, array $months): array
    {
        $accumulated = [];
        $active = [];

        foreach ($percentByProduct as $productId => $percent) {
            foreach ($months as $month) {
                $active[$productId][$month] = $month >= ($sellingStartByProduct[$productId] ?? 0) ? (float) $percent : 0.0;
                $accumulated[$month] = ($accumulated[$month] ?? 0) + $active[$productId][$month];
            }
        }

        $shares = [];
        foreach ($active as $productId => $series) {
            foreach ($series as $month => $percent) {
                $shares[$productId][$month] = $accumulated[$month] ? $percent / $accumulated[$month] : 0;
            }
        }

        return $shares;
    }

    /**
     * The part of a monthly cost that belongs to each product.
     *
     * @param  array<int,array<int,float>>  $revenueShares  from revenueShares()
     * @param  array<int,array<int,float>>  $typedShares    from typedShares()
     * @param  list<int>                    $productIds     every product of the study
     * @param  array<int,float|int>         $cost           month index => cost
     * @return array<int,array<int,float|int>>              product id => month index => cost of that product
     */
    public static function split(array $revenueShares, array $typedShares, array $productIds, array $cost, bool $byRevenue): array
    {
        $result = [];

        foreach ($productIds as $productId) {
            foreach ($cost as $month => $value) {
                // A product with no sales plan takes no share (the old application gave it the whole cost, which counted the cost twice).
                // Only when nobody has any sales plan yet does every product keep the whole cost, as before.
                $missing = $revenueShares === [] ? 1 : 0;
                $revenue = $byRevenue ? ($revenueShares[$productId][$month] ?? $missing) : 1;
                $typed = $byRevenue ? 1 : ($typedShares[$productId][$month] ?? 0);
                $result[$productId][$month] = $revenue * $value * $typed;
            }
        }

        return $result;
    }
}
