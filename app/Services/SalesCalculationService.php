<?php

namespace App\Services;

use App\Calculations\CollectionPolicy;
use App\Calculations\ProductSalesCalculator;
use App\Calculations\RawMaterialCalculator;
use App\Calculations\SeasonalityCalculator;
use App\Calculations\Timeline;
use App\Models\Product;
use App\Models\Project;
use App\Models\RawMaterial;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — SalesCalculationService
//  Location: app/Services/SalesCalculationService.php
//
//  Reads what is typed for the products and raw materials of a study, runs the calculation engine
//  (app/Calculations) and saves the monthly results next to the inputs:
//    products:       monthly sales quantities and values, finished-goods inventory statement,
//                    raw materials consumed, local / export / total collection statements
//    raw materials:  inventory (purchases) statement, supplier payment statement, withholding tax credit statement
//    last of all, the financial statements (App\Services\StatementsService)
//  Called every time the study form, a product form or the raw material payment terms are saved.
// ══════════════════════════════════════════════════════════════════

class SalesCalculationService
{
    public function recalculate(Project $project): void
    {
        $timeline = $this->timeline($project);

        if ($timeline === null) {
            return;
        }

        $consumedByMaterial = [];
        $fx = app(SegmentPricing::class)->fxRates($project);

        foreach ($project->products()->with(['seasonality', 'exportSeasonality', 'rawMaterials'])->orderBy('id')->get() as $product) {
            $result = $this->calculateProduct($project, $product, $timeline, $fx);

            foreach ($result['raw_material_consumed'] as $materialId => $series) {
                if ($materialId === 'total') {
                    continue;
                }
                foreach ($series as $month => $value) {
                    $consumedByMaterial[(int) $materialId][$month] = ($consumedByMaterial[(int) $materialId][$month] ?? 0) + $value;
                }
            }
        }

        foreach ($project->rawMaterials()->orderBy('id')->get() as $material) {
            $this->calculateRawMaterial($project, $material, $timeline, $consumedByMaterial[$material->id] ?? []);
        }

        // Staff and expenses need the sales just calculated (to share costs between the products); the fixed assets
        // come after them, then the opening balances of an existing company, and last the manufacturing cost of each product is added up (as the old application did).
        app(CostCalculationService::class)->recalculate($project, $timeline);
        app(FixedAssetService::class)->recalculate($project, $timeline);
        app(OpeningBalanceService::class)->recalculate($project, $timeline);
        app(CostCalculationService::class)->rollUpProductOverheads($project);
        // Last: cost of goods sold, VAT and tax, and the income statement, cash flow and balance sheet (they add up all of the above).
        app(StatementsService::class)->recalculate($project, $timeline);
    }

    /** The months of the study, or null while the study form has not been saved yet. */
    public function timeline(Project $project): ?Timeline
    {
        $operationStart = $project->operationStartDate();   // saved as a month position, 0 = the study's first month

        if (! $project->start_date || ! $project->duration || $operationStart === null) {
            return null;
        }

        return Timeline::forStudy(
            $project->start_date->format('Y-m-d'),
            (int) $project->duration,
            substr($operationStart, 0, 10),
        );
    }

    /**
     * Works out one product: its monthly sales (every sales channel and export market, each with its own price, quantity and
     * collection terms), finished goods, raw materials consumed and collection statements, and saves them.
     *
     * @param  array<string,array<int,float>>  $fx  the study's FX forecast (see SegmentPricing::fxRates)
     * @return array<string,mixed> the engine result (also saved on the product)
     */
    private function calculateProduct(Project $project, Product $product, Timeline $timeline, array $fx = []): array
    {
        $seasonality = $product->seasonality;
        $exportSeasonality = $product->exportSeasonality;
        $recipes = [];

        foreach ($product->rawMaterials as $material) {
            $recipes[$material->id] = (array) json_decode((string) $material->pivot->percentages, true);
        }

        $pricing = app(SegmentPricing::class);
        $resolved = $pricing->resolve($project, $product, $fx);
        $asEngine = fn (array $group) => array_map(fn ($row) => ['quantities' => $row['quantities'], 'prices' => $row['prices']], $group);

        $input = [
            'selling_start_index'   => (int) $product->selling_start_date,
            'seasonality_weights'   => SeasonalityCalculator::monthWeights($seasonality?->type ?? 'flat', (array) ($seasonality?->percentages ?? [])),
            'local_quantities'      => (array) $product->local_target_quantities,
            'local_prices'          => (array) $product->local_price_per_unit,
            'export_quantities'     => (array) $product->export_target_quantities,
            'export_prices'         => (array) $product->export_price_per_unit,
            'local_segments'        => $asEngine($resolved['local']),
            'export_segments'       => $asEngine($resolved['export']),
            // A new company has no finished goods on the first day.
            'fg_beginning_quantity' => $project->new_company ? 0 : (float) $product->fg_inventory_quantity,
            'fg_coverage_days'      => (float) $product->fg_inventory_coverage_days,
            'recipes'               => $recipes,
        ];

        // Export sales follow their own monthly pattern; with none saved they follow the local one (R4, D6).
        if ($exportSeasonality !== null) {
            $input['export_seasonality_weights'] = SeasonalityCalculator::monthWeights($exportSeasonality->type, (array) $exportSeasonality->percentages);
        }

        $result = ProductSalesCalculator::calculate($timeline, $input);

        $policy = (array) $product->collection_policy_value;
        $vat = (float) $product->vat_rate;
        $withhold = (float) $product->withhold_tax_rate;

        // Every channel / market is collected on its own terms (or the product's default ones) and the statements are added up (R6.9).
        // VAT and withholding tax follow the channel they belong to; export sales carry none (D10).
        $local = $this->collections($result['segments']['local'], $resolved['local'], (array) ($policy['local'] ?? []), $vat, $withhold, $timeline);
        $export = $this->collections($result['segments']['export'], $resolved['export'], (array) ($policy['export'] ?? []), 0, 0, $timeline);

        // What was worked out for each real channel and market is saved with it (the screens and the report read it).
        $statements = ['local' => $local['segments'], 'export' => $export['segments']];
        foreach (['local', 'export'] as $kind) {
            foreach ($resolved[$kind] as $id => $row) {
                $row['segment']?->update([
                    'monthly_quantities'   => $result['segments'][$kind][$id]['quantities'],
                    'monthly_values'       => $result['segments'][$kind][$id]['values'],
                    'collection_statement' => $statements[$kind][$id],
                ]);
            }
        }

        $product->update([
            'monthly_sales_target_values'     => $result['values'],
            'monthly_sales_target_quantities' => $result['quantities'],
            'product_inventory_qt_statement'  => $result['inventory'],
            'product_raw_material_consumed'   => $result['raw_material_consumed'],
            'local_collection_statement'      => $local['total'],
            'export_collection_statement'     => $export['total'],
            'collection_statement'            => CollectionPolicy::addStatements($local['total'], $export['total']),
        ]);

        return $result;
    }

    /**
     * The collection statement of every segment of one group (all channels, or all markets) and their sum.
     *
     * @param  array<int|string,array{quantities:array<int,float>,values:array<int,float>}>  $months  monthly sales of each segment
     * @param  array<int|string,array{segment:?\App\Models\ProductSegment}>  $resolved
     * @param  array<int,array<string,mixed>>  $defaultTerms  the product's terms of this group (first year, later years)
     * @return array{segments:array<int|string,array<string,mixed>>,total:array<string,mixed>}
     */
    private function collections(array $months, array $resolved, array $defaultTerms, float $vat, float $withhold, Timeline $timeline): array
    {
        $statements = [];

        foreach ($months as $id => $sales) {
            $segment = $resolved[$id]['segment'] ?? null;
            $terms = $segment?->uses_own_terms ? (array) $segment->collection_policy_value : $defaultTerms;

            $statements[$id] = CollectionPolicy::statement($sales['values'], $vat, $withhold, $terms[0] ?? [], $terms[1] ?? [], $timeline);
        }

        // One statement is the total as it is; several are added column by column.
        $total = count($statements) === 1 ? reset($statements) : array_reduce($statements, fn ($sum, $one) => $sum === null ? $one : CollectionPolicy::addStatements($sum, $one));

        return ['segments' => $statements, 'total' => $total ?? []];
    }

    /** @param array<int,float|int> $consumed */
    private function calculateRawMaterial(Project $project, RawMaterial $material, Timeline $timeline, array $consumed): void
    {
        ksort($consumed);
        $terms = (array) $material->collection_policy_value;

        $result = RawMaterialCalculator::calculate(
            $timeline,
            $consumed,
            $project->new_company ? 0.0 : (float) $material->beginning_inventory_value,
            (float) $material->rm_inventory_coverage_days,
            (float) $material->vat_rate,
            (float) $material->withhold_tax_rate,
            $terms[0] ?? [],
            $terms[1] ?? [],
        );

        $material->update([
            'inventory_value_statement' => $result['inventory'],
            'collection_statement'      => $result['payments'],
            'credit_withhold_statement' => $result['withhold_credit'],
        ]);
    }
}
