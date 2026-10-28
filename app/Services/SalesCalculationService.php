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

        foreach ($project->products()->with(['seasonality', 'rawMaterials'])->orderBy('id')->get() as $product) {
            $result = $this->calculateProduct($project, $product, $timeline);

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

    /** @return array<string,mixed> the engine result (also saved on the product) */
    private function calculateProduct(Project $project, Product $product, Timeline $timeline): array
    {
        $seasonality = $product->seasonality;
        $recipes = [];

        foreach ($product->rawMaterials as $material) {
            $recipes[$material->id] = (array) json_decode((string) $material->pivot->percentages, true);
        }

        $result = ProductSalesCalculator::calculate($timeline, [
            'selling_start_index'   => (int) $product->selling_start_date,
            'seasonality_weights'   => SeasonalityCalculator::monthWeights($seasonality?->type ?? 'flat', (array) ($seasonality?->percentages ?? [])),
            'local_quantities'      => (array) $product->local_target_quantities,
            'local_prices'          => (array) $product->local_price_per_unit,
            'export_quantities'     => (array) $product->export_target_quantities,
            'export_prices'         => (array) $product->export_price_per_unit,
            // A new company has no finished goods on the first day.
            'fg_beginning_quantity' => $project->new_company ? 0 : (float) $product->fg_inventory_quantity,
            'fg_coverage_days'      => (float) $product->fg_inventory_coverage_days,
            'recipes'               => $recipes,
        ]);

        $policy = (array) $product->collection_policy_value;
        $vat = (float) $product->vat_rate;
        $withhold = (float) $product->withhold_tax_rate;

        $local = CollectionPolicy::statement($result['local_values'], $vat, $withhold, $policy['local'][0] ?? [], $policy['local'][1] ?? [], $timeline);
        // Export sales carry no VAT and no withholding tax.
        $export = CollectionPolicy::statement($result['export_values'], 0, 0, $policy['export'][0] ?? [], $policy['export'][1] ?? [], $timeline);

        $product->update([
            'monthly_sales_target_values'     => $result['values'],
            'monthly_sales_target_quantities' => $result['quantities'],
            'product_inventory_qt_statement'  => $result['inventory'],
            'product_raw_material_consumed'   => $result['raw_material_consumed'],
            'local_collection_statement'      => $local,
            'export_collection_statement'     => $export,
            'collection_statement'            => CollectionPolicy::addStatements($local, $export),
        ]);

        return $result;
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
