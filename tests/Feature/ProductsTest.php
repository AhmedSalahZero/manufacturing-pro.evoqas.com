<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Product;
use App\Models\Project;
use App\Models\Seasonality;
use App\Models\User;
use App\Services\ProjectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

// The product form: yearly sales, monthly pattern, inventory, collections and recipe, and the recalculation it triggers.
// Run:  php artisan test --filter=ProductsTest
class ProductsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
    }

    /** The study form as the screen sends it. */
    private function studyForm(bool $newCompany, ?array $products = null, ?array $materials = null, array $override = []): array
    {
        $selling = $newCompany ? '2026-06' : '2026-01';

        return array_merge([
            'name'                     => 'Plant A',
            'new_company'              => $newCompany,
            'main_functional_currency' => 'egp',
            'intro_paragraph'          => 'A small plant.',
            'business_sector_id'       => null,
            'start_date'               => '2026-01',
            'duration'                 => 5,
            'operation_start_date'     => $newCompany ? '2026-03' : '2026-01',
            'tax_rate'                 => 22.5,
            'return_rate'              => 15,
            'perpetual_growth_rate'    => 3,
            'products'                 => $products ?? [
                ['name' => 'Valve', 'measurement_unit' => 'piece', 'selling_start_date' => $selling, 'vat_rate' => 14, 'withhold_tax_rate' => 1],
                ['name' => 'Pump', 'measurement_unit' => 'piece', 'selling_start_date' => $selling, 'vat_rate' => 14, 'withhold_tax_rate' => 1],
            ],
            'rawMaterials'             => $materials ?? [
                ['name' => 'Brass', 'rm_inventory_coverage_days' => 30, 'measurement_unit' => 'kg', 'vat_rate' => 14, 'withhold_tax_rate' => 0],
                ['name' => 'Steel', 'rm_inventory_coverage_days' => 0, 'measurement_unit' => 'kg', 'vat_rate' => 14, 'withhold_tax_rate' => 0],
            ],
        ], $override);
    }

    /** A saved study: 5 years from 2026-01 (year indexes 0..4), two products and two raw materials. */
    private function study(bool $newCompany = true): Project
    {
        $project = app(ProjectService::class)->create($this->owner, 'Plant A');

        $this->actingAs($this->owner)->put(route('projects.update', $project), $this->studyForm($newCompany))->assertSessionHasNoErrors();

        return $project->refresh();
    }

    /** What the product form sends. */
    private function payload(array $override = []): array
    {
        $each = fn ($value) => array_fill_keys(range(0, 4), $value);
        $policy = [
            ['cash_payment' => 20, 'rate' => [50, 30], 'due_in_days' => [30, 60]],
            ['cash_payment' => 100, 'rate' => [0, 0], 'due_in_days' => [30, 60]],
        ];

        return array_merge([
            'max_capacity'                      => [0 => 6000, 1 => 6600, 2 => 6600, 3 => 6600, 4 => 6600],
            'target_percentages'                => $each(50),
            'local_target_quantity_percentages' => $each(80),
            'local_growth_rates'                => $each(10),
            'local_price_per_unit'              => [0 => 60000, 1 => 66000, 2 => 72600, 3 => 79860, 4 => 87846],
            'export_growth_rates'               => $each(0),
            'export_price_per_unit'             => $each(50000),
            'seasonality'                       => ['type' => 'flat', 'quarterly' => [25, 25, 25, 25], 'monthly' => array_fill(0, 12, 8.33)],
            'fg_inventory_quantity'             => 0,
            'fg_inventory_value'                => 0,
            'fg_inventory_coverage_days'        => 30,
            'collection_policy_value'           => ['local' => $policy, 'export' => $policy],
            'rawMaterials'                      => [],
            'comment_for_sales'                 => 'Test',
            'after'                             => 'stay',
        ], $override);
    }

    private function save(Project $project, Product $product, array $override = [], ?User $by = null)
    {
        return $this->actingAs($by ?? $this->owner)->put(route('projects.products.update', [$project, $product]), $this->payload($override));
    }

    // ── access ──────────────────────────────────────────────

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $project = $this->study();
        $product = $project->products()->first();

        $this->app['auth']->forgetGuards();
        $this->get(route('projects.products.edit', [$project, $product]))->assertRedirect('/login');
    }

    public function test_the_owner_sees_the_form_with_the_years_of_the_study(): void
    {
        $project = $this->study();
        $product = $project->products()->orderBy('id')->first();

        $this->actingAs($this->owner)->get(route('projects.products.edit', [$project, $product]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Products/Edit')
                ->where('product.name', 'Valve')
                ->where('product.selling_start', '2026-06')
                ->has('years', 5)
                ->where('years.0.year', 2026)
                ->where('years.4.year', 2030)
                ->where('seasonality.type', 'flat')
                ->where('project.periods', 2)
                ->has('policy.local', 2)
                ->has('rawMaterials', 2)
                ->where('readOnly', false));
    }

    public function test_a_product_of_another_study_is_not_found_through_this_one(): void
    {
        $project = $this->study();
        $other = app(ProjectService::class)->create($this->owner, 'Other');
        $foreign = $other->products()->create(['name' => 'X', 'measurement_unit' => 'kg', 'selling_start_date' => 0]);

        $this->actingAs($this->owner)->get(route('projects.products.edit', [$project, $foreign]))->assertNotFound();
        $this->save($project, $foreign)->assertNotFound();
    }

    public function test_people_of_another_company_are_refused(): void
    {
        $project = $this->study();
        $product = $project->products()->first();
        $stranger = User::factory()->companyAdmin()->create();

        $this->actingAs($stranger)->get(route('projects.products.edit', [$project, $product]))->assertForbidden();
        $this->save($project, $product, [], $stranger)->assertForbidden();
    }

    public function test_a_colleague_with_view_access_can_look_but_not_save_and_one_with_edit_access_can_save(): void
    {
        $colleague = User::factory()->forCompany(Company::find($this->owner->company_id))->create();
        $project = $this->study();
        $product = $project->products()->first();

        $this->actingAs($this->owner)->put(route('projects.shares', $project), ['shares' => [['user_id' => $colleague->id, 'can_edit' => false]]])->assertSessionHasNoErrors();
        $this->actingAs($colleague)->get(route('projects.products.edit', [$project, $product]))->assertOk()->assertInertia(fn (Assert $p) => $p->where('readOnly', true));
        $this->save($project, $product, [], $colleague)->assertForbidden();

        $this->actingAs($this->owner)->put(route('projects.shares', $project), ['shares' => [['user_id' => $colleague->id, 'can_edit' => true]]])->assertSessionHasNoErrors();
        $this->save($project, $product, ['comment_for_sales' => 'Edited by a colleague'], $colleague)->assertSessionHasNoErrors();
        $this->assertSame('Edited by a colleague', $product->refresh()->comment_for_sales);
    }

    // ── saving ──────────────────────────────────────────────

    public function test_saving_works_out_the_quantities_and_the_sales_values(): void
    {
        $project = $this->study();
        $product = $project->products()->orderBy('id')->first();

        $this->save($project, $product)->assertSessionHasNoErrors()->assertRedirect(route('projects.products.edit', [$project, $product]));

        $product->refresh();
        $this->assertEqualsWithDelta(3000, $product->target_quantities[0], 0.0001);       // 6000 x 50 %
        $this->assertEqualsWithDelta(2400, $product->local_target_quantities[0], 0.0001); // 80 % local
        $this->assertEqualsWithDelta(600, $product->export_target_quantities[0], 0.0001);
        $this->assertEqualsWithDelta(20, $product->export_target_quantity_percentages[0], 0.0001);
        $this->assertEqualsWithDelta(2400 * 60000 + 600 * 50000, $product->sales_target_values[0], 0.01);
        // The figures are kept for one year more than the study shows: the last year repeats.
        $this->assertEquals($product->target_quantities[4], $product->target_quantities[5]);
        $this->assertEquals($product->local_price_per_unit[4], $product->local_price_per_unit[5]);
    }

    public function test_the_study_is_recalculated_month_by_month(): void
    {
        $project = $this->study();
        $product = $project->products()->orderBy('id')->first();

        $this->save($project, $product)->assertSessionHasNoErrors();

        $values = $product->refresh()->monthly_sales_target_values;
        $at = fn (int $month) => (float) ($values[$month] ?? 0);

        // Operation starts in March (month 2) and selling in June (month 5): nothing is sold before.
        $this->assertEqualsWithDelta(0, $at(2), 0.0001);
        $this->assertEqualsWithDelta(0, $at(4), 0.0001);
        $this->assertGreaterThan(0, $at(5));
        // The first year still sells its full yearly target, squeezed into the months from June to December.
        $firstYear = 0;
        foreach (range(0, 11) as $month) {
            $firstYear += $at($month);
        }
        $this->assertEqualsWithDelta(2400 * 60000 + 600 * 50000, $firstYear, 0.5);
        $this->assertArrayHasKey('manufacturing_quantity', $product->product_inventory_qt_statement);
        $this->assertArrayHasKey('monthly', $product->collection_statement);
        $this->assertArrayHasKey('monthly', $product->local_collection_statement);
        $this->assertArrayHasKey('monthly', $product->export_collection_statement);
    }

    public function test_the_results_split_each_year_into_local_and_export_sales(): void
    {
        $project = $this->study();
        $product = $project->products()->orderBy('id')->first();

        $this->save($project, $product)->assertSessionHasNoErrors();

        $this->actingAs($this->owner)->get(route('projects.products.edit', [$project, $product]))->assertInertia(fn (Assert $page) => $page
            ->has('results', 5)
            ->where('results.0.local_value', fn ($v) => abs($v - 2400 * 60000) < 0.5)
            ->where('results.0.export_value', fn ($v) => abs($v - 600 * 50000) < 0.5)
            ->where('results.0.local_quantity', fn ($v) => abs($v - 2400) < 0.001)
            ->where('results.0.export_quantity', fn ($v) => abs($v - 600) < 0.001));
    }

    public function test_the_monthly_pattern_is_saved_and_changes_the_months(): void
    {
        $project = $this->study(false);
        $product = $project->products()->orderBy('id')->first();

        $this->save($project, $product, ['seasonality' => ['type' => 'quarterly', 'quarterly' => [20, 40, 40, 0], 'monthly' => array_fill(0, 12, 8.33)]])->assertSessionHasNoErrors();

        $row = Seasonality::where('model_name', 'Product')->where('model_id', $product->id)->first();
        $this->assertSame('quarterly', $row->type);
        $this->assertEqualsWithDelta(0.4 / 3, (float) $row->distributed_percentages['05'], 1e-9);

        $values = $product->refresh()->monthly_sales_target_values;
        $this->assertEqualsWithDelta(0, (float) ($values[10] ?? 0), 0.0001);              // November: the last quarter sells nothing
        $this->assertGreaterThan((float) $values[0], (float) $values[4]);                  // the second quarter sells twice the first
    }

    public function test_a_pattern_that_does_not_add_up_to_100_is_refused(): void
    {
        $project = $this->study();
        $product = $project->products()->first();

        $this->save($project, $product, ['seasonality' => ['type' => 'quarterly', 'quarterly' => [20, 40, 40, 10], 'monthly' => array_fill(0, 12, 8.33)]])
            ->assertSessionHasErrors('seasonality.quarterly');
    }

    public function test_collection_terms_that_do_not_add_up_to_100_are_refused(): void
    {
        $project = $this->study();
        $product = $project->products()->first();
        $bad = ['cash_payment' => 20, 'rate' => [50, 20], 'due_in_days' => [30, 60]];
        $good = ['cash_payment' => 100, 'rate' => [0, 0], 'due_in_days' => [30, 60]];

        $this->save($project, $product, ['collection_policy_value' => ['local' => [$good, $good], 'export' => [$good, $bad]]])
            ->assertSessionHasErrors('collection_policy_value.export.1');
    }

    public function test_equal_due_days_are_merged_when_saving(): void
    {
        $project = $this->study();
        $product = $project->products()->first();
        $merged = ['cash_payment' => 50, 'rate' => [25, 25], 'due_in_days' => [30, 30]];
        $cash = ['cash_payment' => 100, 'rate' => [0, 0], 'due_in_days' => [30, 60]];

        $this->save($project, $product, ['collection_policy_value' => ['local' => [$merged, $cash], 'export' => [$cash, $cash]]])->assertSessionHasNoErrors();

        $stored = $product->refresh()->collection_policy_value['local'][0];
        $this->assertEquals(['30'], array_values($stored['due_in_days']));
        $this->assertEquals([50.0], array_map('floatval', array_values($stored['rate'])));
    }

    public function test_the_recipe_is_saved_and_the_raw_material_is_consumed(): void
    {
        $project = $this->study();
        $product = $project->products()->orderBy('id')->first();
        $brass = $project->rawMaterials()->where('name', 'Brass')->first();

        $this->save($project, $product, ['rawMaterials' => [['raw_material_id' => $brass->id, 'percentages' => array_fill_keys(range(0, 4), 30)]]])->assertSessionHasNoErrors();

        $product->refresh();
        $this->assertCount(1, $product->rawMaterials);
        $this->assertEquals(30, json_decode($product->rawMaterials->first()->pivot->percentages, true)[0]);
        $this->assertGreaterThan(0, array_sum($product->product_raw_material_consumed[$brass->id]));

        // What the products consume is what the raw material has to buy and pay for.
        $brass->refresh();
        $this->assertGreaterThan(0, array_sum($brass->inventory_value_statement['manufacturing_quantity']));
        $this->assertArrayHasKey('monthly', $brass->collection_statement);
    }

    public function test_a_raw_material_of_another_study_is_refused(): void
    {
        $project = $this->study();
        $product = $project->products()->first();
        $other = app(ProjectService::class)->create($this->owner, 'Other');
        $foreign = $other->rawMaterials()->create(['name' => 'Foreign', 'rm_inventory_coverage_days' => 0, 'measurement_unit' => 'kg']);

        $this->save($project, $product, ['rawMaterials' => [['raw_material_id' => $foreign->id, 'percentages' => [0 => 10]]]])
            ->assertSessionHasErrors('rawMaterials.0.raw_material_id');
    }

    public function test_the_same_raw_material_twice_is_refused(): void
    {
        $project = $this->study();
        $product = $project->products()->first();
        $line = ['raw_material_id' => $project->rawMaterials()->first()->id, 'percentages' => [0 => 10]];

        $this->save($project, $product, ['rawMaterials' => [$line, $line]])->assertSessionHasErrors('rawMaterials');
    }

    public function test_a_new_company_has_no_opening_finished_goods(): void
    {
        $project = $this->study(true);
        $product = $project->products()->first();

        $this->save($project, $product, ['fg_inventory_quantity' => 500, 'fg_inventory_value' => 100000])->assertSessionHasNoErrors();

        $this->assertEquals(0, (float) $product->refresh()->fg_inventory_quantity);
    }

    public function test_an_existing_company_keeps_its_opening_finished_goods_and_their_split(): void
    {
        $project = $this->study(false);
        $product = $project->products()->orderBy('id')->first();

        $this->save($project, $product, [
            'fg_inventory_quantity'             => 1500,
            'fg_inventory_value'                => 9000000,
            'fg_inventory_coverage_days'        => 75,
            'fg_beginning_inventory_breakdowns' => ['raw_material_value' => ['percentage' => 50], 'direct_labor_value' => ['percentage' => 30], 'manufacturing_overheads_value' => ['percentage' => 20]],
        ])->assertSessionHasNoErrors();

        $product->refresh();
        $this->assertEquals(1500, (float) $product->fg_inventory_quantity);
        $this->assertEquals(75, (int) $product->fg_inventory_coverage_days);
        $this->assertEquals(4500000, (float) $product->fg_beginning_inventory_breakdowns['raw_material_value']['value']);
        $this->assertEquals(1800000, (float) $product->fg_beginning_inventory_breakdowns['manufacturing_overheads_value']['value']);
        // 1500 units in stock cover the first months (250 a month), so nothing has to be made in January.
        $this->assertEquals(0, (float) $product->product_inventory_qt_statement['manufacturing_quantity'][0]);
    }

    public function test_an_opening_inventory_split_must_add_up_to_100(): void
    {
        $project = $this->study(false);
        $product = $project->products()->first();

        $this->save($project, $product, [
            'fg_inventory_quantity'             => 1500,
            'fg_inventory_value'                => 9000000,
            'fg_beginning_inventory_breakdowns' => ['raw_material_value' => ['percentage' => 50], 'direct_labor_value' => ['percentage' => 30], 'manufacturing_overheads_value' => ['percentage' => 10]],
        ])->assertSessionHasErrors('fg_beginning_inventory_breakdowns');
    }

    public function test_numbers_must_be_numbers_and_percentages_at_most_100(): void
    {
        $project = $this->study();
        $product = $project->products()->first();

        $this->save($project, $product, ['target_percentages' => [0 => 120, 1 => 50, 2 => 50, 3 => 50, 4 => 50], 'max_capacity' => [0 => 'abc', 1 => 1, 2 => 1, 3 => 1, 4 => 1]])
            ->assertSessionHasErrors(['target_percentages.0', 'max_capacity.0']);
    }

    // ── moving on ───────────────────────────────────────────

    public function test_save_and_next_goes_to_the_next_product_then_to_the_raw_material_payments(): void
    {
        $project = $this->study();
        [$first, $second] = $project->products()->orderBy('id')->get()->all();

        $this->save($project, $first, ['after' => 'next'])->assertRedirect(route('projects.products.edit', [$project, $second]));
        $this->save($project, $second, ['after' => 'next'])->assertRedirect(route('projects.raw-materials.edit', $project));
    }

    public function test_saving_the_study_again_keeps_the_product_sales(): void
    {
        $project = $this->study();
        $product = $project->products()->orderBy('id')->first();
        $this->save($project, $product)->assertSessionHasNoErrors();

        $before = array_sum($product->refresh()->monthly_sales_target_values);

        // The study form is saved again with a new tax rate: the sales figures stay.
        $products = $project->products()->orderBy('id')->get()->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'measurement_unit' => 'piece', 'selling_start_date' => '2026-06', 'vat_rate' => 14, 'withhold_tax_rate' => 1])->all();
        $materials = $project->rawMaterials()->orderBy('id')->get()->map(fn ($m) => ['id' => $m->id, 'name' => $m->name, 'rm_inventory_coverage_days' => 30, 'measurement_unit' => 'kg', 'vat_rate' => 14, 'withhold_tax_rate' => 0])->all();

        $this->actingAs($this->owner)->put(route('projects.update', $project), $this->studyForm(true, $products, $materials, ['tax_rate' => 20, 'intro_paragraph' => 'Changed.']))
            ->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta($before, array_sum($product->refresh()->monthly_sales_target_values), 0.01);
    }
}
