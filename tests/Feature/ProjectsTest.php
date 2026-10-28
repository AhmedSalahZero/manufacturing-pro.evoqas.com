<?php

namespace Tests\Feature;

use App\Models\BusinessSector;
use App\Models\Expense;
use App\Models\Manpower;
use App\Models\Product;
use App\Models\Project;
use App\Models\Seasonality;
use App\Models\User;
use App\Services\ProjectService;
use Database\Seeders\BusinessSectorSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

// The studies: list, create, edit form, save, copy, delete. Run:  php artisan test --filter=ProjectsTest
class ProjectsTest extends TestCase
{
    use RefreshDatabase;

    /** A complete, valid study form (what the screen sends). */
    private function form(array $override = []): array
    {
        return array_merge([
            'name'                     => 'Plant A',
            'new_company'              => true,
            'main_functional_currency' => 'egp',
            'intro_paragraph'          => 'A small plant.',
            'business_sector_id'       => null,
            'start_date'               => '2026-01',
            'duration'                 => 5,
            'operation_start_date'     => '2026-03',
            'tax_rate'                 => 22.5,
            'return_rate'              => 15,
            'perpetual_growth_rate'    => 3,
            'products'                 => [
                ['name' => 'Valve', 'measurement_unit' => 'piece', 'selling_start_date' => '2026-06', 'vat_rate' => 14, 'withhold_tax_rate' => 1],
            ],
            'rawMaterials'             => [
                ['name' => 'Brass', 'rm_inventory_coverage_days' => 30, 'measurement_unit' => 'kg', 'vat_rate' => 14, 'withhold_tax_rate' => 0],
            ],
        ], $override);
    }

    private function saved(User $user, array $override = []): Project
    {
        $project = app(ProjectService::class)->create($user, 'Plant A');
        $this->actingAs($user)->put(route('projects.update', $project), $this->form($override))->assertSessionHasNoErrors();

        return $project->refresh();
    }

    // ── access ──────────────────────────────────────────────

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $this->get('/projects')->assertRedirect('/login');
    }

    public function test_the_list_shows_only_my_studies(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();
        app(ProjectService::class)->create($me, 'Mine');
        app(ProjectService::class)->create($other, 'Not mine');

        $this->actingAs($me)->get('/projects')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Projects/Index')
            ->has('studies', 1)
            ->where('studies.0.name', 'Mine'));
    }

    public function test_another_person_cannot_open_change_copy_or_delete_my_study(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();
        $project = app(ProjectService::class)->create($me, 'Mine');

        $this->actingAs($other)->get(route('projects.edit', $project))->assertForbidden();
        $this->actingAs($other)->put(route('projects.update', $project), $this->form())->assertForbidden();
        $this->actingAs($other)->post(route('projects.copy', $project), ['name' => 'Stolen'])->assertForbidden();
        $this->actingAs($other)->delete(route('projects.destroy', $project))->assertForbidden();

        $this->assertNotSoftDeleted($project);
        $this->assertSame(1, Project::count());
    }

    // ── create ──────────────────────────────────────────────

    public function test_a_new_study_needs_only_a_name_and_opens_its_form(): void
    {
        $me = User::factory()->create();

        $response = $this->actingAs($me)->post('/projects', ['name' => '  Plant A  ']);

        $project = Project::firstOrFail();
        $response->assertRedirect(route('projects.edit', $project));
        $this->assertSame('Plant A', $project->name);
        $this->assertSame($me->id, $project->user_id);
        $this->assertTrue($project->new_company);
        $this->assertSame($me->company_id, $project->company_id);
    }

    public function test_a_new_study_without_a_name_is_refused(): void
    {
        $me = User::factory()->create();

        $this->actingAs($me)->post('/projects', ['name' => ''])->assertSessionHasErrors('name');
        $this->assertSame(0, Project::count());
    }

    // ── the form ────────────────────────────────────────────

    public function test_the_form_opens_with_its_lists(): void
    {
        $me = User::factory()->create();
        $this->seed(BusinessSectorSeeder::class);
        $project = $this->saved($me);

        $this->actingAs($me)->get(route('projects.edit', $project))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Projects/Edit')
            ->where('project.name', 'Plant A')
            ->where('project.start_date', '2026-01')
            ->where('project.operation_start_date', '2026-03')
            ->where('products.0.name', 'Valve')
            ->where('products.0.selling_start_date', '2026-06')
            ->where('rawMaterials.0.name', 'Brass')
            ->has('sectors', 24)
            ->has('options.currencies'));
    }

    // ── save ────────────────────────────────────────────────

    public function test_saving_fills_the_study_and_works_out_its_dates(): void
    {
        $me = User::factory()->create();
        $project = $this->saved($me);

        $this->assertTrue($project->is_completed);
        $this->assertSame('2026-01-01', $project->start_date->format('Y-m-d'));
        $this->assertSame('2030-12-01', $project->end_date->format('Y-m-d'));
        $this->assertSame('2031-12-01', $project->extended_end_date->format('Y-m-d'));
        // Saved as a position in the study months: study starts 2026-01, operation 2026-03 = position 2.
        $this->assertSame(2, $project->operation_start_date);
        $this->assertSame('2026-03-01', $project->operationStartDate());
        $this->assertSame('2026-01-01', $project->study_dates[0]);
        $this->assertSame('2026-03-01', $project->operation_dates[2]);
        $this->assertSame('plant-a', $project->slug);
    }

    public function test_a_product_remembers_its_selling_start_as_a_month_position(): void
    {
        $me = User::factory()->create();
        $project = $this->saved($me);

        // January 2026 is position 0, so June 2026 is position 5.
        $this->assertSame(5, (int) $project->products()->firstOrFail()->selling_start_date);
        $this->assertSame(1, $project->rawMaterials()->count());
    }

    public function test_saving_again_updates_rows_instead_of_adding_them(): void
    {
        $me = User::factory()->create();
        $project = $this->saved($me);
        $product = $project->products()->firstOrFail();
        $material = $project->rawMaterials()->firstOrFail();

        $this->actingAs($me)->put(route('projects.update', $project), $this->form([
            'products'     => [['id' => $product->id, 'name' => 'Valve XL', 'measurement_unit' => 'piece', 'selling_start_date' => '2026-07', 'vat_rate' => 14, 'withhold_tax_rate' => 1]],
            'rawMaterials' => [['id' => $material->id, 'name' => 'Bronze', 'rm_inventory_coverage_days' => 60, 'measurement_unit' => 'kg', 'vat_rate' => 14, 'withhold_tax_rate' => 0]],
        ]))->assertSessionHasNoErrors();

        $this->assertSame(1, $project->products()->count());
        $this->assertSame('Valve XL', $product->refresh()->name);
        $this->assertSame(6, (int) $product->selling_start_date);
        $this->assertSame('Bronze', $material->refresh()->name);
        $this->assertSame(60, (int) $material->rm_inventory_coverage_days);
    }

    public function test_save_and_close_returns_to_the_list(): void
    {
        $me = User::factory()->create();
        $project = app(ProjectService::class)->create($me, 'Plant A');

        $this->actingAs($me)->put(route('projects.update', $project), $this->form(['after' => 'list']))
            ->assertRedirect(route('projects.index'));
    }

    public function test_removing_a_product_removes_its_data_and_its_place_in_expenses(): void
    {
        $me = User::factory()->create();
        $project = $this->saved($me, ['products' => [
            ['name' => 'Valve', 'measurement_unit' => 'piece', 'selling_start_date' => '2026-06', 'vat_rate' => 14, 'withhold_tax_rate' => 1],
            ['name' => 'Pump', 'measurement_unit' => 'piece', 'selling_start_date' => '2026-06', 'vat_rate' => 14, 'withhold_tax_rate' => 1],
        ]]);
        [$valve, $pump] = $project->products()->orderBy('id')->get()->all();

        Seasonality::create(['model_id' => $pump->id, 'model_name' => 'Product', 'type' => 'flat', 'percentages' => [], 'distributed_percentages' => [], 'project_id' => $project->id]);
        $expense = Expense::create(['name' => 'Packing', 'products' => [$valve->id, $pump->id], 'collection_statements' => [], 'project_id' => $project->id]);

        $this->actingAs($me)->put(route('projects.update', $project), $this->form([
            'products' => [['id' => $valve->id, 'name' => 'Valve', 'measurement_unit' => 'piece', 'selling_start_date' => '2026-06', 'vat_rate' => 14, 'withhold_tax_rate' => 1]],
        ]))->assertSessionHasNoErrors();

        $this->assertNull(Product::find($pump->id));
        $this->assertSame(0, Seasonality::where('model_name', 'Product')->where('model_id', $pump->id)->count());
        $this->assertEquals([$valve->id], $expense->refresh()->products);
    }

    public function test_a_new_company_has_no_opening_balances_but_an_existing_one_keeps_them(): void
    {
        $me = User::factory()->create();
        $project = $this->saved($me, ['new_company' => false, 'operation_start_date' => '2026-01']);
        $project->cashAndBankOpeningBalances()->create(['cash_and_bank_amount' => 500]);

        $this->actingAs($me)->put(route('projects.update', $project), $this->form(['new_company' => false, 'operation_start_date' => '2026-01']))->assertSessionHasNoErrors();
        $this->assertSame(1, $project->cashAndBankOpeningBalances()->count());

        $this->actingAs($me)->put(route('projects.update', $project), $this->form(['new_company' => true]))->assertSessionHasNoErrors();
        $this->assertSame(0, $project->cashAndBankOpeningBalances()->count());
    }

    // ── what is refused ─────────────────────────────────────

    public function test_the_return_rate_is_needed_from_two_years_and_the_growth_rate_from_three(): void
    {
        $me = User::factory()->create();
        $project = app(ProjectService::class)->create($me, 'Plant A');

        $this->actingAs($me)->put(route('projects.update', $project), $this->form(['duration' => 3, 'return_rate' => null, 'perpetual_growth_rate' => null]))
            ->assertSessionHasErrors(['return_rate', 'perpetual_growth_rate']);

        $this->actingAs($me)->put(route('projects.update', $project), $this->form(['duration' => 2, 'perpetual_growth_rate' => null]))
            ->assertSessionHasNoErrors();

        $this->actingAs($me)->put(route('projects.update', $project), $this->form(['duration' => 1, 'return_rate' => null, 'perpetual_growth_rate' => null, 'products' => [
            ['name' => 'Valve', 'measurement_unit' => 'piece', 'selling_start_date' => '2026-06', 'vat_rate' => 14, 'withhold_tax_rate' => 1],
        ]]))->assertSessionHasNoErrors();

        $this->assertNull($project->refresh()->return_rate);
        $this->assertNull($project->perpetual_growth_rate);
    }

    public function test_the_study_must_last_one_to_seven_years(): void
    {
        $me = User::factory()->create();
        $project = app(ProjectService::class)->create($me, 'Plant A');

        $this->actingAs($me)->put(route('projects.update', $project), $this->form(['duration' => 8]))->assertSessionHasErrors('duration');
        $this->actingAs($me)->put(route('projects.update', $project), $this->form(['duration' => 0]))->assertSessionHasErrors('duration');
    }

    public function test_an_existing_company_starts_operating_with_the_study(): void
    {
        $me = User::factory()->create();
        $project = app(ProjectService::class)->create($me, 'Plant A');

        $this->actingAs($me)->put(route('projects.update', $project), $this->form(['new_company' => false, 'operation_start_date' => '2026-03']))
            ->assertSessionHasErrors('operation_start_date');
    }

    public function test_operation_cannot_start_before_the_study_or_after_it_ends(): void
    {
        $me = User::factory()->create();
        $project = app(ProjectService::class)->create($me, 'Plant A');

        $this->actingAs($me)->put(route('projects.update', $project), $this->form(['operation_start_date' => '2025-12']))->assertSessionHasErrors('operation_start_date');
        $this->actingAs($me)->put(route('projects.update', $project), $this->form(['operation_start_date' => '2031-01']))->assertSessionHasErrors('operation_start_date');
    }

    public function test_a_product_cannot_start_selling_before_operation_starts(): void
    {
        $me = User::factory()->create();
        $project = app(ProjectService::class)->create($me, 'Plant A');

        $this->actingAs($me)->put(route('projects.update', $project), $this->form(['products' => [
            ['name' => 'Valve', 'measurement_unit' => 'piece', 'selling_start_date' => '2026-02', 'vat_rate' => 14, 'withhold_tax_rate' => 1],
        ]]))->assertSessionHasErrors('products.0.selling_start_date');
    }

    public function test_at_least_one_product_and_one_raw_material_are_needed(): void
    {
        $me = User::factory()->create();
        $project = app(ProjectService::class)->create($me, 'Plant A');

        $this->actingAs($me)->put(route('projects.update', $project), $this->form(['products' => [], 'rawMaterials' => []]))
            ->assertSessionHasErrors(['products', 'rawMaterials']);
    }

    public function test_rates_must_be_between_0_and_100_and_units_must_be_known(): void
    {
        $me = User::factory()->create();
        $project = app(ProjectService::class)->create($me, 'Plant A');

        $this->actingAs($me)->put(route('projects.update', $project), $this->form([
            'tax_rate'     => 101,
            'products'     => [['name' => 'Valve', 'measurement_unit' => 'furlong', 'selling_start_date' => '2026-06', 'vat_rate' => -1, 'withhold_tax_rate' => 1]],
            'rawMaterials' => [['name' => 'Brass', 'rm_inventory_coverage_days' => 31, 'measurement_unit' => 'kg', 'vat_rate' => 14, 'withhold_tax_rate' => 0]],
        ]))->assertSessionHasErrors(['tax_rate', 'products.0.measurement_unit', 'products.0.vat_rate', 'rawMaterials.0.rm_inventory_coverage_days']);
    }

    public function test_a_product_id_from_another_study_is_refused(): void
    {
        $me = User::factory()->create();
        $first = $this->saved($me);
        $second = app(ProjectService::class)->create($me, 'Plant B');

        $this->actingAs($me)->put(route('projects.update', $second), $this->form(['products' => [
            ['id' => $first->products()->firstOrFail()->id, 'name' => 'Valve', 'measurement_unit' => 'piece', 'selling_start_date' => '2026-06', 'vat_rate' => 14, 'withhold_tax_rate' => 1],
        ]]))->assertSessionHasErrors('products.0.id');
    }

    public function test_the_messages_are_in_the_chosen_language(): void
    {
        $me = User::factory()->create(['language' => 'ar']);
        $project = app(ProjectService::class)->create($me, 'Plant A');

        $this->actingAs($me)->put(route('projects.update', $project), $this->form(['duration' => null]))
            ->assertSessionHasErrors('duration');
        $this->assertSame('من فضلك اختر المدة.', session('errors')->first('duration'));
    }

    // ── copy and delete ─────────────────────────────────────

    public function test_a_copy_is_a_full_independent_study(): void
    {
        $me = User::factory()->create();
        $project = $this->saved($me, ['products' => [
            ['name' => 'Valve', 'measurement_unit' => 'piece', 'selling_start_date' => '2026-06', 'vat_rate' => 14, 'withhold_tax_rate' => 1],
            ['name' => 'Pump', 'measurement_unit' => 'piece', 'selling_start_date' => '2026-06', 'vat_rate' => 14, 'withhold_tax_rate' => 1],
        ]]);
        [$valve, $pump] = $project->products()->orderBy('id')->get()->all();

        Manpower::create(['type' => 'direct', 'position' => 'Welder', 'avg_salary' => '9000', 'existing_count' => '2', 'hirings' => [], 'project_id' => $project->id]);
        Expense::create(['name' => 'Packing', 'products' => [$valve->id, $pump->id], 'collection_statements' => [], 'project_id' => $project->id]);
        Seasonality::create(['model_id' => $pump->id, 'model_name' => 'Product', 'type' => 'flat', 'percentages' => [], 'distributed_percentages' => [], 'project_id' => $project->id]);

        $this->actingAs($me)->post(route('projects.copy', $project), ['name' => 'Plant A copy'])
            ->assertRedirect(route('projects.index'))
            ->assertSessionHas('success');

        $copy = Project::where('name', 'Plant A copy')->firstOrFail();
        $this->assertNotSame($project->id, $copy->id);
        $this->assertSame($me->id, $copy->user_id);
        $this->assertEquals($project->study_dates, $copy->study_dates);
        $this->assertSame(2, $copy->products()->count());
        $this->assertSame(1, $copy->rawMaterials()->count());
        $this->assertSame(1, $copy->manpowers()->count());

        // The copy points at its own products, never at the originals.
        $copyIds = $copy->products()->pluck('id')->all();
        $copyExpense = $copy->expenses()->firstOrFail();
        $this->assertEqualsCanonicalizing($copyIds, $copyExpense->products);
        $this->assertEmpty(array_intersect($copyIds, [$valve->id, $pump->id]));

        $copyPump = $copy->products()->where('name', 'Pump')->firstOrFail();
        $this->assertSame(1, Seasonality::where('project_id', $copy->id)->where('model_id', $copyPump->id)->count());

        // The original is untouched.
        $this->assertSame(2, $project->products()->count());
        $this->assertEquals([$valve->id, $pump->id], $project->expenses()->firstOrFail()->products);
    }

    public function test_a_copy_needs_a_name(): void
    {
        $me = User::factory()->create();
        $project = $this->saved($me);

        $this->actingAs($me)->post(route('projects.copy', $project), ['name' => ' '])->assertSessionHasErrors('name');
        $this->assertSame(1, Project::count());
    }

    public function test_deleting_hides_the_study_but_keeps_it_in_the_database(): void
    {
        $me = User::factory()->create();
        $project = $this->saved($me);

        $this->actingAs($me)->delete(route('projects.destroy', $project))->assertRedirect(route('projects.index'));

        $this->assertSoftDeleted($project);
        $this->actingAs($me)->get('/projects')->assertInertia(fn (Assert $page) => $page->has('studies', 0));
    }

    // ── business sectors ────────────────────────────────────

    public function test_the_sector_list_has_24_sectors_and_can_be_run_twice(): void
    {
        $this->seed(BusinessSectorSeeder::class);
        $this->seed(BusinessSectorSeeder::class);

        $this->assertSame(24, BusinessSector::count());
        $this->assertSame(0, BusinessSector::whereNull('name_ar')->orWhere('name_ar', '')->count());
    }
}
