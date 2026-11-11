<?php

namespace App\Services;

use App\Calculations\SegmentCalculator;
use App\Models\Product;
use App\Models\Project;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — SegmentPricing
//  Location: app/Services/SegmentPricing.php
//
//  Reads what is saved for a product's sales channels and export markets and works out, for every one of them, the yearly
//  quantity and the yearly price in the STUDY currency (see App\Calculations\SegmentCalculator for the rules):
//    channel:  quantity = local quantity x share %;     price = base local price x (1 + difference %)
//    market:   quantity = export quantity x share %;    price = foreign price x FX rate of the year
//  A product that has no channel (or no market) saved behaves as ONE implicit channel "local" at the product's local price
//  (or ONE implicit market "export" at the product's export price): this is how every study worked before channels and markets
//  existed, and what a study with no channels or markets defined still does (R8).
// ══════════════════════════════════════════════════════════════════

class SegmentPricing
{
    /**
     * The year indexes the product's sales table shows: from the year it starts selling to the last year of the study.
     * (0 = the calendar year the study starts in.)
     *
     * @return list<int>
     */
    public static function yearsOf(Project $project, Product $product): array
    {
        $startYear = (int) $project->start_date->format('Y');
        $endYear = (int) $project->end_date->format('Y');
        $sellingMonth = ($project->extended_study_dates ?? [])[$product->selling_start_date] ?? null;
        $sellingYear = $sellingMonth ? (int) substr($sellingMonth, 0, 4) : $startYear;

        $first = max(0, $sellingYear - $startYear);
        $last = max($first, $endYear - $startYear);

        return range($first, $last);
    }

    /**
     * The FX forecast of the study: currency => year index => rate. The extended end year repeats the last year.
     *
     * @return array<string,array<int,float>>
     */
    public function fxRates(Project $project): array
    {
        $result = [];

        foreach ($project->fxRates()->get() as $row) {
            $result[$row->currency] = SegmentCalculator::extend(array_map('floatval', (array) $row->rates));
        }

        return $result;
    }

    /**
     * @param  array<string,array<int,float>>|null  $fx  from fxRates() (pass it when resolving many products)
     * @return array{
     *     local:array<int|string,array{segment:?\App\Models\ProductSegment,quantities:array<int,float>,prices:array<int,float>}>,
     *     export:array<int|string,array{segment:?\App\Models\ProductSegment,quantities:array<int,float>,prices:array<int,float>}>
     * }  keyed by the segment id; the implicit channel / market is keyed 'local' / 'export' and has segment = null
     */
    public function resolve(Project $project, Product $product, ?array $fx = null): array
    {
        $segments = $product->segments()->with('exportMarket')->orderBy('id')->get();
        $fx ??= $this->fxRates($project);
        $years = self::yearsOf($project, $product);

        $localGroup = array_map('floatval', (array) $product->local_target_quantities);
        $exportGroup = array_map('floatval', (array) $product->export_target_quantities);
        $local = $export = [];

        foreach ($segments as $segment) {
            if ($segment->kind === 'local') {
                $local[$segment->id] = [
                    'segment'    => $segment,
                    'quantities' => SegmentCalculator::quantities($localGroup, SegmentCalculator::extend((array) $segment->allocation_percentages)),
                    'prices'     => SegmentCalculator::localPrices((array) $product->local_price_per_unit, SegmentCalculator::extend((array) $segment->price_differences)),
                ];

                continue;
            }

            $foreign = SegmentCalculator::extend(SegmentCalculator::foreignPrices((float) $segment->first_foreign_price, (array) $segment->foreign_growth_rates, $years));
            $currency = $segment->exportMarket?->currency;

            $export[$segment->id] = [
                'segment'    => $segment,
                'quantities' => SegmentCalculator::quantities($exportGroup, SegmentCalculator::extend((array) $segment->allocation_percentages)),
                'prices'     => SegmentCalculator::exportPrices($foreign, $fx[$currency] ?? []),
            ];
        }

        // No channel / market saved: the product's own local / export quantity and price, as before.
        if ($local === []) {
            $local['local'] = ['segment' => null, 'quantities' => $localGroup, 'prices' => array_map('floatval', (array) $product->local_price_per_unit)];
        }
        if ($export === []) {
            $export['export'] = ['segment' => null, 'quantities' => $exportGroup, 'prices' => array_map('floatval', (array) $product->export_price_per_unit)];
        }

        return ['local' => $local, 'export' => $export];
    }

    /** True when the product has at least one real channel or market saved. */
    public function hasSegments(array $resolved): bool
    {
        foreach ($resolved as $group) {
            foreach ($group as $row) {
                if ($row['segment'] !== null) {
                    return true;
                }
            }
        }

        return false;
    }
}
