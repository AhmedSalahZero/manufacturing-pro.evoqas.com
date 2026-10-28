<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ProjectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

// Every start date is saved as a position counted from the study start (0 = first month), so the study moves as one.
// Run:  php artisan test --filter=StudyDatesAsIndexTest
class StudyDatesAsIndexTest extends TestCase
{
    use RefreshDatabase;

    private function form(string $start, string $operation, string $selling, ?int $productId = null, ?int $materialId = null): array
    {
        return [
            'name' => 'Plant A', 'new_company' => true, 'main_functional_currency' => 'egp', 'intro_paragraph' => '', 'business_sector_id' => null,
            'start_date' => $start, 'duration' => 5, 'operation_start_date' => $operation, 'tax_rate' => 22.5, 'return_rate' => 15, 'perpetual_growth_rate' => 3,
            'products' => [['id' => $productId, 'name' => 'Valve', 'measurement_unit' => 'piece', 'selling_start_date' => $selling, 'vat_rate' => 14, 'withhold_tax_rate' => 1]],
            'rawMaterials' => [['id' => $materialId, 'name' => 'Brass', 'rm_inventory_coverage_days' => 30, 'measurement_unit' => 'kg', 'vat_rate' => 14, 'withhold_tax_rate' => 0]],
        ];
    }

    public function test_the_operation_start_and_the_selling_start_are_saved_as_positions(): void
    {
        $me = User::factory()->create();
        $project = app(ProjectService::class)->create($me, 'Plant A');

        // Study starts January 2027 (0), operation April 2027 (3), selling July 2027 (6).
        $this->actingAs($me)->put(route('projects.update', $project), $this->form('2027-01', '2027-04', '2027-07'))->assertSessionHasNoErrors();

        $project->refresh();
        $this->assertSame(3, $project->operation_start_date);
        $this->assertSame('2027-04-01', $project->operationStartDate());
        $this->assertSame(6, $project->products()->first()->selling_start_date);
    }

    public function test_moving_the_study_start_moves_every_date_with_it(): void
    {
        $me = User::factory()->create();
        $project = app(ProjectService::class)->create($me, 'Plant A');
        $this->actingAs($me)->put(route('projects.update', $project), $this->form('2027-01', '2027-04', '2027-07'))->assertSessionHasNoErrors();

        // The form moves the other months by the same two months when the start changes from January to March.
        $this->actingAs($me)->put(route('projects.update', $project), $this->form('2027-03', '2027-06', '2027-09'))->assertSessionHasNoErrors();

        $project->refresh();
        $this->assertSame(3, $project->operation_start_date);
        $this->assertSame('2027-06-01', $project->operationStartDate());
        $this->assertSame(6, $project->products()->first()->selling_start_date);

        $this->actingAs($me)->get(route('projects.edit', $project))->assertInertia(fn (Assert $page) => $page
            ->where('project.start_date', '2027-03')
            ->where('project.operation_start_date', '2027-06')
            ->where('products.0.selling_start_date', '2027-09'));
    }

    public function test_the_monthly_results_follow_the_positions(): void
    {
        $me = User::factory()->create();
        $project = app(ProjectService::class)->create($me, 'Plant A');
        $this->actingAs($me)->put(route('projects.update', $project), $this->form('2027-01', '2027-04', '2027-07'))->assertSessionHasNoErrors();
        $product = $project->products()->first();

        $this->actingAs($me)->put(route('projects.products.update', [$project, $product]), [
            'max_capacity' => array_fill_keys(range(0, 4), 1200), 'target_percentages' => array_fill_keys(range(0, 4), 100),
            'local_target_quantity_percentages' => array_fill_keys(range(0, 4), 100), 'local_growth_rates' => array_fill_keys(range(0, 4), 0),
            'local_price_per_unit' => array_fill_keys(range(0, 4), 10), 'export_growth_rates' => array_fill_keys(range(0, 4), 0), 'export_price_per_unit' => array_fill_keys(range(0, 4), 0),
            'seasonality' => ['type' => 'flat'], 'fg_inventory_coverage_days' => 0,
            'collection_policy_value' => ['local' => array_fill(0, 2, ['cash_payment' => 100, 'rate' => [0, 0], 'due_in_days' => [30, 60]]), 'export' => array_fill(0, 2, ['cash_payment' => 100, 'rate' => [0, 0], 'due_in_days' => [30, 60]])],
        ])->assertSessionHasNoErrors();

        $first = $product->refresh()->monthly_sales_target_values;
        $this->assertEqualsWithDelta(0, (float) ($first[5] ?? 0), 0.0001);   // June 2027, before selling starts (position 6)
        $this->assertGreaterThan(0, (float) $first[6]);

        // The whole study moves two months later: selling still starts at position 6 (now September 2027).
        $this->actingAs($me)->put(route('projects.update', $project), $this->form('2027-03', '2027-06', '2027-09', $product->id, $project->rawMaterials()->value('id')))->assertSessionHasNoErrors();

        $moved = $product->refresh()->monthly_sales_target_values;
        $this->assertEqualsWithDelta(0, (float) ($moved[5] ?? 0), 0.0001);
        $this->assertGreaterThan(0, (float) $moved[6]);
        // The yearly target is still sold in full in the first calendar year (now over September to December only).
        $this->assertEqualsWithDelta(12000, array_sum(array_intersect_key($moved, array_flip(range(0, 9)))), 0.5);
    }
}
