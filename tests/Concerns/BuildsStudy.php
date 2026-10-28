<?php

namespace Tests\Concerns;

use App\Models\Project;
use App\Models\User;
use App\Services\ProjectService;
use Illuminate\Testing\TestResponse;

// A small finished study for the screens that only READ results (dashboard, what-if, public links, report).
// The class using it must have `private User $owner` (set in setUp) and extend Tests\TestCase.
trait BuildsStudy
{
    /** A 3-year study from 2026-01. Valve sells 100 pieces a month at 10 (1 000 a month, VAT 14 %); Pump has no sales plan. */
    private function study(bool $newCompany = true): Project
    {
        $project = app(ProjectService::class)->create($this->owner, 'Plant A');

        $this->actingAs($this->owner)->put(route('projects.update', $project), [
            'name' => 'Plant A', 'new_company' => $newCompany, 'main_functional_currency' => 'egp', 'intro_paragraph' => 'A small valve plant.', 'business_sector_id' => null,
            'start_date' => '2026-01', 'duration' => 3, 'operation_start_date' => '2026-01', 'tax_rate' => 22.5, 'return_rate' => 15, 'perpetual_growth_rate' => 3,
            'products' => [
                ['name' => 'Valve', 'measurement_unit' => 'piece', 'selling_start_date' => '2026-01', 'vat_rate' => 14, 'withhold_tax_rate' => 1],
                ['name' => 'Pump', 'measurement_unit' => 'piece', 'selling_start_date' => '2026-01', 'vat_rate' => 14, 'withhold_tax_rate' => 1],
            ],
            'rawMaterials' => [['name' => 'Brass', 'rm_inventory_coverage_days' => 0, 'measurement_unit' => 'kg', 'vat_rate' => 14, 'withhold_tax_rate' => 0]],
        ])->assertSessionHasNoErrors();

        $project->refresh();
        $valve = $project->products()->where('name', 'Valve')->first();
        $terms = array_fill(0, 2, ['cash_payment' => 40, 'rate' => [60, 0], 'due_in_days' => [30, 60]]);

        $this->actingAs($this->owner)->put(route('projects.products.update', [$project, $valve]), [
            'max_capacity' => [0 => 1200, 1 => 1200, 2 => 1200], 'target_percentages' => [0 => 100, 1 => 100, 2 => 100],
            'local_target_quantity_percentages' => [0 => 100, 1 => 100, 2 => 100], 'local_growth_rates' => [0 => 0, 1 => 0, 2 => 0],
            'local_price_per_unit' => [0 => 10, 1 => 10, 2 => 10], 'export_growth_rates' => [0 => 0, 1 => 0, 2 => 0], 'export_price_per_unit' => [0 => 0, 1 => 0, 2 => 0],
            'seasonality' => ['type' => 'flat'], 'fg_inventory_coverage_days' => 0, 'fg_inventory_quantity' => 0, 'fg_inventory_value' => 0,
            'collection_policy_value' => ['local' => $terms, 'export' => $terms],
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->owner)->put(route('projects.assets.update', $project), ['after' => 'stay', 'rows' => [[
            'name' => 'Press', 'counts' => 2, 'amount' => 6000, 'depreciation_duration' => 5, 'start' => '2026-01', 'end' => '2026-03',
            'admin_depreciation_percentage' => 30, 'replacement_cost_rate' => 0, 'replacement_cost_interval' => 1,
            'payment_terms' => 'cash', 'equity_funding_rate' => 50, 'interest_rate' => 12, 'grace_period' => 3, 'tenor' => 24, 'installment_interval' => 'quarterly',
            'is_as_revenue_percentages' => true,
        ]]])->assertSessionHasNoErrors();

        return $project->refresh();
    }

    /** The props of an Inertia page as plain arrays (floats stay floats). @return array<string,mixed> */
    private function props(TestResponse $response): array
    {
        $page = $response->viewData('page');

        return json_decode(is_string($page) ? $page : json_encode($page), true)['props'];
    }

    private function asGuest(): void
    {
        $this->app['auth']->forgetGuards();
    }
}
