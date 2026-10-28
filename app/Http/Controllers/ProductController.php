<?php

namespace App\Http\Controllers;

use App\Calculations\CollectionPolicy;
use App\Calculations\SeasonalityCalculator;
use App\Http\Requests\UpdateProductRequest;
use App\Models\Product;
use App\Models\Project;
use App\Services\ProductPlanService;
use App\Services\SalesCalculationService;
use App\Support\StudyCalendar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — ProductController (sales, inventory and collections of one product)
//  Location: app/Http/Controllers/ProductController.php
//
//  edit    the product form         /projects/{project}/products/{product}/edit
//  update  save it (and recalculate the study)   PUT /projects/{project}/products/{product}
//
//  The products themselves (names, units, selling start, VAT) are added on the study form.
//  Who may do what: App\Policies\ProjectPolicy. The saving is done by App\Services\ProductPlanService.
// ══════════════════════════════════════════════════════════════════

class ProductController extends Controller
{
    public function __construct(
        private readonly ProductPlanService $plans,
        private readonly SalesCalculationService $calculations,
    ) {
    }

    public function edit(Request $request, Project $project, Product $product): Response
    {
        Gate::authorize('view', $project);

        $years = $this->plans->viewYears($project, $product);
        $startYear = (int) $project->start_date->format('Y');
        $dates = $project->extended_study_dates ?? [];

        $byYear = fn (?array $stored, mixed $default = '') => collect($years)
            ->mapWithKeys(fn ($y) => [$y => $stored[$y] ?? $default])->all();

        $seasonality = $product->seasonality;
        $policy = (array) $product->collection_policy_value;
        $periods = $project->duration > 1 ? 2 : 1;

        $recipe = $product->rawMaterials->map(fn ($m) => [
            'raw_material_id' => $m->id,
            'percentages'     => $byYear((array) json_decode((string) $m->pivot->percentages, true), 0),
        ])->values();

        return Inertia::render('Products/Edit', [
            'project' => [
                'id'          => $project->id,
                'name'        => $project->name,
                'new_company' => (bool) $project->new_company,
                'duration'    => (int) $project->duration,
                'periods'     => $periods,
            ],
            'product' => [
                'id'               => $product->id,
                'name'             => $product->name,
                'measurement_unit' => $product->measurement_unit,
                'selling_start'    => StudyCalendar::monthAt($dates, $product->selling_start_date),
                'vat_rate'         => (float) $product->vat_rate,
                'withhold_rate'    => (float) $product->withhold_tax_rate,
                'comment'          => $product->comment_for_sales,
            ],
            'years' => collect($years)->map(fn ($y) => ['index' => $y, 'year' => $startYear + $y])->values(),
            'sales' => [
                'max_capacity'                      => $byYear((array) $product->max_capacity),
                'target_percentages'                => $byYear((array) $product->target_percentages, 100),
                'local_target_quantity_percentages' => $byYear((array) $product->local_target_quantity_percentages, 100),
                'local_growth_rates'                => $byYear((array) $product->local_growth_rates, 0),
                'local_price_per_unit'              => $byYear((array) $product->local_price_per_unit),
                'export_growth_rates'               => $byYear((array) $product->export_growth_rates, 0),
                'export_price_per_unit'             => $byYear((array) $product->export_price_per_unit),
            ],
            'seasonality' => [
                'type'      => $seasonality?->type ?? 'flat',
                'quarterly' => $seasonality?->type === 'quarterly' ? array_map('floatval', (array) $seasonality->percentages) : [25, 25, 25, 25],
                'monthly'   => $seasonality?->type === 'monthly' ? array_map('floatval', (array) $seasonality->percentages) : [8.33, 8.33, 8.33, 8.33, 8.33, 8.33, 8.33, 8.33, 8.34, 8.34, 8.34, 8.34],
            ],
            'inventory' => [
                'quantity'    => (float) $product->fg_inventory_quantity,
                'value'       => (float) $product->fg_inventory_value,
                'coverage'    => (int) ($product->fg_inventory_coverage_days ?? 30),
                'breakdowns'  => collect(ProductPlanService::FG_BREAKDOWN_TYPES)->mapWithKeys(fn ($t) => [
                    $t => (float) (($product->fg_beginning_inventory_breakdowns[$t]['percentage'] ?? null) ?? ($t === 'raw_material_value' ? 100 : 0)),
                ])->all(),
            ],
            'policy' => [
                'local'  => $this->policyRows($policy['local'] ?? [], $periods),
                'export' => $this->policyRows($policy['export'] ?? [], $periods),
            ],
            'recipe'       => $recipe,
            'rawMaterials' => $project->rawMaterials()->orderBy('id')->get(['id', 'name', 'measurement_unit'])->values(),
            'nextProduct'  => $project->products()->where('id', '>', $product->id)->orderBy('id')->value('id'),
            'results'      => $this->results($project, $product, $years, $startYear),
            'readOnly'     => ! $request->user()->can('update', $project),
            'options'      => ['dueDays' => CollectionPolicy::DUE_DAYS, 'coverageDays' => ProductPlanService::FG_COVERAGE_DAYS],
        ]);
    }

    public function update(UpdateProductRequest $request, Project $project, Product $product): RedirectResponse
    {
        $this->plans->save($project, $product, $request->validated());

        return match ($request->input('after')) {
            'list'  => redirect()->route('projects.index')->with('success', __('products.saved')),
            'next'  => $this->next($project, $product),
            default => redirect()->route('projects.products.edit', [$project, $product])->with('success', __('products.saved')),
        };
    }

    /** The next product's form, or the raw material payment terms after the last product. */
    private function next(Project $project, Product $product): RedirectResponse
    {
        $next = $project->products()->where('id', '>', $product->id)->orderBy('id')->first();

        return ($next
            ? redirect()->route('projects.products.edit', [$project, $next])
            : redirect()->route('projects.raw-materials.edit', $project))->with('success', __('products.saved'));
    }

    /** @return list<array<string,mixed>> one row per period (first operation year, following years), always with two payment slots */
    private function policyRows(array $stored, int $periods): array
    {
        $rows = [];

        for ($i = 0; $i < $periods; $i++) {
            $period = $stored[$i] ?? [];
            $rows[] = [
                'cash_payment' => (float) ($period['cash_payment'] ?? 100),
                'rate'         => [(float) ($period['rate'][0] ?? 0), (float) ($period['rate'][1] ?? 0)],
                'due_in_days'  => [(int) ($period['due_in_days'][0] ?? 30), (int) ($period['due_in_days'][1] ?? 60)],
            ];
        }

        return $rows;
    }

    /**
     * What the engine calculated, added up per year (shown at the bottom of the form).
     *
     * @param  list<int>  $years
     * @return list<array<string,mixed>>
     */
    private function results(Project $project, Product $product, array $years, int $startYear): array
    {
        $timeline = $this->calculations->timeline($project);
        $values = (array) $product->monthly_sales_target_values;

        if ($timeline === null || $values === []) {
            return [];
        }

        $quantities = (array) $product->monthly_sales_target_quantities;
        $made = (array) ($product->product_inventory_qt_statement['manufacturing_quantity'] ?? []);
        $closing = (array) ($product->product_inventory_qt_statement['end_balance'] ?? []);
        $localValues = (array) ($product->local_collection_statement['monthly']['expense'] ?? []);
        $exportValues = (array) ($product->export_collection_statement['monthly']['expense'] ?? []);
        $targets = (array) $product->target_quantities;
        $localTargets = (array) $product->local_target_quantities;
        $rows = [];

        foreach ($years as $year) {
            $months = array_filter(array_keys($values), fn ($m) => $timeline->yearIndexOf((int) $m) === $year);
            $sold = array_sum(array_map(fn ($m) => $quantities[$m] ?? 0, $months));
            // The yearly target is split between local and export in the same proportion as the units sold.
            $localShare = (float) ($targets[$year] ?? 0) > 0 ? (float) ($localTargets[$year] ?? 0) / (float) $targets[$year] : 0;
            $rows[] = [
                'year'              => $startYear + $year,
                'sales_value'       => array_sum(array_map(fn ($m) => $values[$m], $months)),
                'local_value'       => array_sum(array_map(fn ($m) => $localValues[$m] ?? 0, $months)),
                'export_value'      => array_sum(array_map(fn ($m) => $exportValues[$m] ?? 0, $months)),
                'sales_quantity'    => $sold,
                'local_quantity'    => $sold * $localShare,
                'export_quantity'   => $sold * (1 - $localShare),
                'made_quantity'     => array_sum(array_map(fn ($m) => $made[$m] ?? 0, $months)),
                'closing_inventory' => $months === [] ? 0 : ($closing[max($months)] ?? 0),
            ];
        }

        return $rows;
    }
}
