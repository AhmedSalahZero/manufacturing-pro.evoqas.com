<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Services\ProjectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

// How the raw materials are paid for. Run:  php artisan test --filter=RawMaterialPaymentsTest
class RawMaterialPaymentsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
    }

    /** A 3-year study (indexes 0..2) of an existing company, so that operation and selling start in January 2026. */
    private function study(): Project
    {
        $project = app(ProjectService::class)->create($this->owner, 'Plant A');

        $this->actingAs($this->owner)->put(route('projects.update', $project), [
            'name' => 'Plant A', 'new_company' => false, 'main_functional_currency' => 'egp', 'intro_paragraph' => '', 'business_sector_id' => null,
            'start_date' => '2026-01', 'duration' => 3, 'operation_start_date' => '2026-01', 'tax_rate' => 22.5, 'return_rate' => 15, 'perpetual_growth_rate' => 3,
            'products' => [['name' => 'Valve', 'measurement_unit' => 'piece', 'selling_start_date' => '2026-01', 'vat_rate' => 14, 'withhold_tax_rate' => 1]],
            'rawMaterials' => [
                ['name' => 'Brass', 'rm_inventory_coverage_days' => 30, 'measurement_unit' => 'kg', 'vat_rate' => 14, 'withhold_tax_rate' => 0],
                ['name' => 'Steel', 'rm_inventory_coverage_days' => 0, 'measurement_unit' => 'kg', 'vat_rate' => 14, 'withhold_tax_rate' => 0],
            ],
        ])->assertSessionHasNoErrors();

        return $project->refresh();
    }

    private function payload(Project $project, ?array $terms = null): array
    {
        $terms ??= [
            ['cash_payment' => 50, 'rate' => [50, 0], 'due_in_days' => [30, 60]],
            ['cash_payment' => 0, 'rate' => [100, 0], 'due_in_days' => [60, 90]],
        ];

        return [
            'rawMaterials'              => $project->rawMaterials()->orderBy('id')->pluck('id')->mapWithKeys(fn ($id) => [$id => ['collection_policy_value' => $terms]])->all(),
            'comment_for_raw_materials' => 'Paid by cheque.',
            'after'                     => 'stay',
        ];
    }

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $project = $this->study();

        $this->app['auth']->forgetGuards();
        $this->get(route('projects.raw-materials.edit', $project))->assertRedirect('/login');
    }

    public function test_the_page_lists_every_raw_material_with_two_periods(): void
    {
        $project = $this->study();

        $this->actingAs($this->owner)->get(route('projects.raw-materials.edit', $project))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('RawMaterials/Payments')
            ->has('materials', 2)
            ->where('materials.0.name', 'Brass')
            ->has('materials.0.policy', 2)
            ->where('project.periods', 2)
            ->where('readOnly', false));
    }

    public function test_the_terms_are_saved_and_the_supplier_balance_is_worked_out(): void
    {
        $project = $this->study();
        $product = $project->products()->first();
        $brass = $project->rawMaterials()->where('name', 'Brass')->first();
        $cash = array_fill(0, 2, ['cash_payment' => 100, 'rate' => [0, 0], 'due_in_days' => [30, 60]]);

        // Give the product some sales and a recipe so that there is something to buy.
        $this->actingAs($this->owner)->put(route('projects.products.update', [$project, $product]), [
            'max_capacity' => [0 => 1200, 1 => 1200, 2 => 1200], 'target_percentages' => [0 => 100, 1 => 100, 2 => 100],
            'local_target_quantity_percentages' => [0 => 100, 1 => 100, 2 => 100], 'local_growth_rates' => [0 => 0, 1 => 0, 2 => 0],
            'local_price_per_unit' => [0 => 10, 1 => 10, 2 => 10], 'export_growth_rates' => [0 => 0, 1 => 0, 2 => 0], 'export_price_per_unit' => [0 => 0, 1 => 0, 2 => 0],
            'seasonality' => ['type' => 'flat'], 'fg_inventory_coverage_days' => 0,
            'fg_inventory_quantity' => 0, 'fg_inventory_value' => 0,
            'collection_policy_value' => ['local' => $cash, 'export' => $cash],
            'rawMaterials' => [['raw_material_id' => $brass->id, 'percentages' => [0 => 40, 1 => 40, 2 => 40]]],
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->owner)->put(route('projects.raw-materials.update', $project), $this->payload($project))
            ->assertSessionHasNoErrors()->assertRedirect(route('projects.raw-materials.edit', $project));

        $brass->refresh();
        $this->assertEquals(50, (float) $brass->collection_policy_value[0]['cash_payment']);
        $this->assertSame('Paid by cheque.', $project->refresh()->comment_for_raw_materials);
        $this->assertGreaterThan(0, array_sum($brass->collection_statement['monthly']['total_due']));
        $this->assertGreaterThan(0, array_sum($brass->collection_statement['monthly']['payment']));
    }

    public function test_terms_that_do_not_add_up_to_100_are_refused(): void
    {
        $project = $this->study();
        $brass = $project->rawMaterials()->orderBy('id')->first();
        $payload = $this->payload($project);
        $payload['rawMaterials'][$brass->id]['collection_policy_value'][0] = ['cash_payment' => 50, 'rate' => [20, 0], 'due_in_days' => [30, 60]];

        $this->actingAs($this->owner)->put(route('projects.raw-materials.update', $project), $payload)
            ->assertSessionHasErrors("rawMaterials.{$brass->id}.collection_policy_value.0");
    }

    public function test_a_stranger_cannot_look_or_save(): void
    {
        $project = $this->study();
        $stranger = User::factory()->companyAdmin()->create();

        $this->actingAs($stranger)->get(route('projects.raw-materials.edit', $project))->assertForbidden();
        $this->actingAs($stranger)->put(route('projects.raw-materials.update', $project), $this->payload($project))->assertForbidden();
    }
}
