<?php

namespace App\Services;

use App\Calculations\ProductSalesCalculator;
use App\Calculations\SeasonalityCalculator;
use App\Calculations\SegmentCalculator;
use App\Models\Product;
use App\Models\ProductSegment;
use App\Models\Project;
use App\Models\Seasonality;
use Illuminate\Support\Facades\DB;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — ProductPlanService (the product sales form)
//  Location: app/Services/ProductPlanService.php
//
//  Saves what is typed on a product's form: yearly sales figures, monthly pattern (seasonality), finished-goods
//  inventory, collection terms and the raw materials it uses. The yearly quantities and sales values are always
//  worked out here from the typed numbers (never trusted from the browser). Then the whole study is recalculated.
// ══════════════════════════════════════════════════════════════════

class ProductPlanService
{
    /** Finished goods opening inventory is split into these three parts (percentages of the inventory value). */
    public const FG_BREAKDOWN_TYPES = ['raw_material_value', 'direct_labor_value', 'manufacturing_overheads_value'];

    public const FG_COVERAGE_DAYS = [0, 15, 30, 45, 60, 75, 90, 120, 150, 180];

    public function __construct(private readonly SalesCalculationService $calculations)
    {
    }

    /**
     * The year indexes the product's sales table shows: from the year it starts selling to the last year of the study.
     * (0 = the calendar year the study starts in.)
     *
     * @return list<int>
     */
    public function viewYears(Project $project, Product $product): array
    {
        return SegmentPricing::yearsOf($project, $product);
    }

    /**
     * @param  array<string,mixed>  $data  already validated (see UpdateProductRequest)
     */
    public function save(Project $project, Product $product, array $data): void
    {
        DB::transaction(function () use ($project, $product, $data) {
            $years = $this->viewYears($project, $product);

            $capacity = $this->perYear($data['max_capacity'] ?? [], $years);
            $targetPercent = $this->perYear($data['target_percentages'] ?? [], $years);
            $localPercent = $this->perYear($data['local_target_quantity_percentages'] ?? [], $years);
            $localGrowth = $this->perYear($data['local_growth_rates'] ?? [], $years);
            $exportGrowth = $this->perYear($data['export_growth_rates'] ?? [], $years);
            $localPrice = $this->perYear($data['local_price_per_unit'] ?? [], $years);
            $exportPrice = $this->perYear($data['export_price_per_unit'] ?? [], $years);

            $quantities = ProductSalesCalculator::yearlyQuantities($capacity, $targetPercent, $localPercent);
            $exportPercent = array_map(fn ($p) => 100 - $p, $localPercent);

            $salesValues = [];
            foreach ($years as $year) {
                $salesValues[$year] = $quantities['local'][$year] * $localPrice[$year] + $quantities['export'][$year] * $exportPrice[$year];
            }

            $values = [
                'max_capacity'                       => $this->extend($capacity),
                'target_percentages'                 => $this->extend($targetPercent),
                'target_quantities'                  => $this->extend($quantities['target']),
                'local_target_quantity_percentages'  => $this->extend($localPercent),
                'local_target_quantities'            => $this->extend($quantities['local']),
                'local_growth_rates'                 => $this->extend($localGrowth),
                'local_price_per_unit'               => $this->extend($localPrice),
                'export_target_quantity_percentages' => $this->extend($exportPercent),
                'export_target_quantities'           => $this->extend($quantities['export']),
                'export_growth_rates'                => $this->extend($exportGrowth),
                'export_price_per_unit'              => $this->extend($exportPrice),
                'sales_target_values'                => $this->extend($salesValues),
                'collection_policy_type'             => 'customize',
                'collection_policy_interval'         => 'monthly',
                'collection_policy_value'            => $this->normalisePolicy($data['collection_policy_value'], $project),
                'fg_inventory_coverage_days'         => (string) ($data['fg_inventory_coverage_days'] ?? 30),
                'comment_for_sales'                  => $data['comment_for_sales'] ?? null,
            ];

            // An existing company has finished goods on the first day; a new company has none (the fields are not shown).
            if (! $project->new_company) {
                $inventoryValue = (float) ($data['fg_inventory_value'] ?? 0);
                $values['fg_inventory_quantity'] = (float) ($data['fg_inventory_quantity'] ?? 0);
                $values['fg_inventory_value'] = (string) $inventoryValue;
                $values['fg_beginning_inventory_breakdowns'] = $this->breakdowns($data['fg_beginning_inventory_breakdowns'] ?? [], $inventoryValue);
            }

            $product->update($values);

            $this->saveSeasonality($project, $product, $data['seasonality'], Seasonality::LOCAL);
            if (isset($data['export_seasonality'])) {
                $this->saveSeasonality($project, $product, $data['export_seasonality'], Seasonality::EXPORT);
            }
            if (array_key_exists('segments', $data)) {
                $this->saveSegments($project, $product, (array) $data['segments'], $years);
            }
            $this->refreshSegmentSales($project, $product, $years);
            $this->saveRecipe($project, $product, $data['rawMaterials'] ?? [], $years);
        });

        $this->calculations->recalculate($project);
    }

    /** @param  array<string,mixed>  $input  year index => typed number @param list<int> $years @return array<int,float> */
    private function perYear(array $input, array $years): array
    {
        $result = [];

        foreach ($years as $year) {
            $result[$year] = (float) ($input[$year] ?? 0);
        }

        return $result;
    }

    /** The figures are kept for one year more than the study shows: the extra year repeats the last one (the study's extended end year). */
    private function extend(array $byYear): array
    {
        if ($byYear !== []) {
            $last = array_key_last($byYear);
            $byYear[$last + 1] = $byYear[$last];
        }

        return $byYear;
    }

    /**
     * One policy for the first operation year and one for the following years (only when the study lasts more than a year).
     * Equal due days are merged: their rates are added.
     *
     * @return array{local:list<array<string,mixed>>, export:list<array<string,mixed>>}
     */
    private function normalisePolicy(array $policy, Project $project): array
    {
        $periods = $project->duration > 1 ? 2 : 1;
        $result = [];

        foreach (['local', 'export'] as $stream) {
            for ($i = 0; $i < $periods; $i++) {
                $result[$stream][$i] = self::mergeDueDays($policy[$stream][$i] ?? []);
            }
        }

        return $result;
    }

    /** @return array{cash_payment:string, rate:list<string>, due_in_days:list<string>} */
    public static function mergeDueDays(array $period): array
    {
        $merged = [];

        foreach ($period['due_in_days'] ?? [] as $n => $days) {
            if ($days === null || $days === '') {
                continue;
            }
            $merged[(int) $days] = ($merged[(int) $days] ?? 0) + (float) ($period['rate'][$n] ?? 0);
        }

        return [
            'cash_payment' => (string) (float) ($period['cash_payment'] ?? 0),
            'rate'         => array_map(fn ($r) => (string) $r, array_values($merged)),
            'due_in_days'  => array_map(fn ($d) => (string) $d, array_keys($merged)),
        ];
    }

    /** @return array<string,array{name:string,percentage:string,value:string}> */
    private function breakdowns(array $input, float $inventoryValue): array
    {
        $names = [
            'raw_material_value'            => 'Raw Material Value',
            'direct_labor_value'            => 'Direct Labor Value',
            'manufacturing_overheads_value' => 'Manufacturing Overheads Value',
        ];
        $result = [];

        foreach (self::FG_BREAKDOWN_TYPES as $type) {
            $percentage = (float) ($input[$type]['percentage'] ?? 0);
            $result[$type] = [
                'name'       => $names[$type],
                'percentage' => (string) $percentage,
                'value'      => (string) ($percentage / 100 * $inventoryValue),
            ];
        }

        return $result;
    }

    private function saveSeasonality(Project $project, Product $product, array $input, string $scope): void
    {
        $type = $input['type'];
        $percentages = array_map(fn ($v) => $v === null ? 0 : (float) $v, array_values($input[$type] ?? []));

        $row = [
            'type'                    => $type,
            'percentages'             => $percentages,
            'distributed_percentages' => SeasonalityCalculator::monthWeights($type, $percentages),
            'project_id'              => $project->id,
        ];

        Seasonality::updateOrCreate(['model_name' => 'Product', 'model_id' => $product->id, 'scope' => $scope], $row);
    }

    /**
     * Saves how the product uses the study's sales channels (local) and export markets (export): the share of each year, the price
     * difference of a channel, the first foreign price and growth of a market, and the terms of a segment that has its own.
     * A channel or market that is not in the list any more is removed from the product.
     *
     * @param  array{local?:list<array<string,mixed>>,export?:list<array<string,mixed>>}  $segments
     * @param  list<int>  $years
     */
    private function saveSegments(Project $project, Product $product, array $segments, array $years): void
    {
        $kept = [];

        foreach (['local' => 'sales_channel_id', 'export' => 'export_market_id'] as $kind => $column) {
            foreach (array_values((array) ($segments[$kind] ?? [])) as $row) {
                $own = (bool) ($row['uses_own_terms'] ?? false);
                $values = [
                    'project_id'             => $project->id,
                    'allocation_percentages' => $this->perYear((array) ($row['allocation_percentages'] ?? []), $years),
                    'uses_own_terms'         => $own,
                    'collection_policy_value' => $own ? $this->normaliseTerms((array) ($row['collection_policy_value'] ?? []), $project) : null,
                ];

                if ($kind === 'local') {
                    $values['price_differences'] = $this->perYear((array) ($row['price_differences'] ?? []), $years);
                } else {
                    $values['first_foreign_price'] = (float) ($row['first_foreign_price'] ?? 0);
                    $values['foreign_growth_rates'] = $this->perYear((array) ($row['foreign_growth_rates'] ?? []), $years);
                }

                $kept[] = ProductSegment::updateOrCreate(
                    ['product_id' => $product->id, 'kind' => $kind, $column => (int) $row[$column]],
                    $values,
                )->id;
            }
        }

        ProductSegment::where('product_id', $product->id)->whereNotIn('id', $kept)->delete();
    }

    /** One set of terms per period (first operation year, following years), equal due days merged. @return list<array<string,mixed>> */
    private function normaliseTerms(array $terms, Project $project): array
    {
        $periods = $project->duration > 1 ? 2 : 1;
        $result = [];

        for ($i = 0; $i < $periods; $i++) {
            $result[$i] = self::mergeDueDays($terms[$i] ?? []);
        }

        return $result;
    }

    /**
     * With channels or markets saved, the yearly sales value is the sum of quantity x price over all of them, and the export price
     * kept on the product is the average export price in the study currency (so the screens and reports that read the product
     * show what is really sold). A product with none keeps the figures worked out from its own prices.
     *
     * @param  list<int>  $years
     */
    private function refreshSegmentSales(Project $project, Product $product, array $years): void
    {
        $pricing = app(SegmentPricing::class);
        $resolved = $pricing->resolve($project, $product->refresh());

        if (! $pricing->hasSegments($resolved)) {
            return;
        }

        $all = array_merge(array_values($resolved['local']), array_values($resolved['export']));
        $values = [];
        $exportPrices = [];
        foreach ($years as $year) {
            $values[$year] = SegmentCalculator::totalValue($all, $year);
            $exportPrices[$year] = SegmentCalculator::averagePrice($resolved['export'], $year);
        }

        $changes = ['sales_target_values' => $this->extend($values)];
        if (collect($resolved['export'])->contains(fn ($row) => $row['segment'] !== null)) {
            $changes['export_price_per_unit'] = $this->extend($exportPrices);
        }

        $product->update($changes);
    }

    /** @param list<array<string,mixed>> $lines @param list<int> $years */
    private function saveRecipe(Project $project, Product $product, array $lines, array $years): void
    {
        $product->rawMaterials()->detach();
        $seen = [];

        foreach ($lines as $line) {
            $id = (int) ($line['raw_material_id'] ?? 0);

            if (! $id || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;

            $product->rawMaterials()->attach($id, [
                'percentages' => json_encode($this->extend($this->perYear($line['percentages'] ?? [], $years))),
                'project_id'  => $project->id,
            ]);
        }
    }
}
