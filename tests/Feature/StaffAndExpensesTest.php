<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Expense;
use App\Models\Manpower;
use App\Models\Product;
use App\Models\ProductExpenseAllocation;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

// The staff form and the expenses form: saving, checks, access, and the numbers that come out.
// Run:  php artisan test --filter=StaffAndExpensesTest
class StaffAndExpensesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
    }

    /**
     * A 3-year study from 2026-01 (months 0..35; with the extra year 0..47) of an existing company.
     * Valve sells 1000 a month from January 2026; Pump has no sales plan.
     */
    private function study(bool $newCompany = false): Project
    {
        $project = app(ProjectService::class)->create($this->owner, 'Plant A');

        $this->actingAs($this->owner)->put(route('projects.update', $project), [
            'name' => 'Plant A', 'new_company' => $newCompany, 'main_functional_currency' => 'egp', 'intro_paragraph' => '', 'business_sector_id' => null,
            'start_date' => '2026-01', 'duration' => 3, 'operation_start_date' => '2026-01', 'tax_rate' => 22.5, 'return_rate' => 15, 'perpetual_growth_rate' => 3,
            'products' => [
                ['name' => 'Valve', 'measurement_unit' => 'piece', 'selling_start_date' => '2026-01', 'vat_rate' => 14, 'withhold_tax_rate' => 1],
                ['name' => 'Pump', 'measurement_unit' => 'piece', 'selling_start_date' => '2026-01', 'vat_rate' => 14, 'withhold_tax_rate' => 1],
            ],
            'rawMaterials' => [['name' => 'Brass', 'rm_inventory_coverage_days' => 0, 'measurement_unit' => 'kg', 'vat_rate' => 14, 'withhold_tax_rate' => 0]],
        ])->assertSessionHasNoErrors();

        $project->refresh();
        $valve = $project->products()->where('name', 'Valve')->first();
        $cash = array_fill(0, 2, ['cash_payment' => 100, 'rate' => [0, 0], 'due_in_days' => [30, 60]]);

        $this->actingAs($this->owner)->put(route('projects.products.update', [$project, $valve]), [
            'max_capacity' => [0 => 1200, 1 => 1200, 2 => 1200], 'target_percentages' => [0 => 100, 1 => 100, 2 => 100],
            'local_target_quantity_percentages' => [0 => 100, 1 => 100, 2 => 100], 'local_growth_rates' => [0 => 0, 1 => 0, 2 => 0],
            'local_price_per_unit' => [0 => 10, 1 => 10, 2 => 10], 'export_growth_rates' => [0 => 0, 1 => 0, 2 => 0], 'export_price_per_unit' => [0 => 0, 1 => 0, 2 => 0],
            'seasonality' => ['type' => 'flat'], 'fg_inventory_coverage_days' => 0, 'fg_inventory_quantity' => 0, 'fg_inventory_value' => 0,
            'collection_policy_value' => ['local' => $cash, 'export' => $cash],
        ])->assertSessionHasNoErrors();

        return $project->refresh();
    }

    private function staff(?array $rows = null, array $over = []): array
    {
        return array_merge([
            'salaries_annual_increase' => 10, 'salaries_tax_rate' => 5, 'social_insurance_rate' => 5,
            'rows' => $rows ?? [['type' => 'direct_labor', 'position' => 'Welder', 'avg_salary' => 1000, 'existing_count' => 2, 'hirings' => [0 => 1]]],
            'after' => 'stay',
        ], $over);
    }

    private function fixed(array $over = []): array
    {
        return array_merge([
            'relation_name' => 'fixed_monthly_repeating_amount', 'category_id' => 'manufacturing-expenses', 'name' => 'Rent', 'amount' => 10000,
            'start' => '2026-01', 'end' => '2028-12', 'increase_rate' => 10, 'payment_terms' => 'cash', 'is_as_revenue_percentages' => true,
        ], $over);
    }

    private function expenses(array $rows): array
    {
        return ['rows' => $rows, 'after' => 'stay'];
    }

    // ── Access ──────────────────────────────────────────────────────────────

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $project = $this->study();
        $this->app['auth']->forgetGuards();

        $this->get(route('projects.manpower.edit', $project))->assertRedirect('/login');
        $this->get(route('projects.expenses.edit', $project))->assertRedirect('/login');
    }

    public function test_a_stranger_cannot_look_or_save(): void
    {
        $project = $this->study();
        $stranger = User::factory()->companyAdmin()->create();

        $this->actingAs($stranger)->get(route('projects.manpower.edit', $project))->assertForbidden();
        $this->actingAs($stranger)->put(route('projects.manpower.update', $project), $this->staff())->assertForbidden();
        $this->actingAs($stranger)->get(route('projects.expenses.edit', $project))->assertForbidden();
        $this->actingAs($stranger)->put(route('projects.expenses.update', $project), $this->expenses([$this->fixed()]))->assertForbidden();
    }

    public function test_a_colleague_with_view_access_can_look_but_not_save(): void
    {
        $company = Company::factory()->create();
        $owner = User::factory()->forCompany($company)->create();
        $viewer = User::factory()->forCompany($company)->create();
        $this->owner = $owner;
        $project = $this->study();

        $this->actingAs($owner)->put(route('projects.shares', $project), ['shares' => [['user_id' => $viewer->id, 'can_edit' => false]]])->assertSessionHasNoErrors();

        $this->actingAs($viewer)->get(route('projects.manpower.edit', $project))->assertOk()->assertInertia(fn (Assert $page) => $page->where('readOnly', true));
        $this->actingAs($viewer)->get(route('projects.expenses.edit', $project))->assertOk()->assertInertia(fn (Assert $page) => $page->where('readOnly', true));
        $this->actingAs($viewer)->put(route('projects.manpower.update', $project), $this->staff())->assertForbidden();
        $this->actingAs($viewer)->put(route('projects.expenses.update', $project), $this->expenses([$this->fixed()]))->assertForbidden();
    }

    // ── Staff ───────────────────────────────────────────────────────────────

    public function test_the_staff_page_lists_the_operation_months_and_the_types(): void
    {
        $project = $this->study();

        $this->actingAs($this->owner)->get(route('projects.manpower.edit', $project))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Manpower/Edit')
            ->has('types', 6)
            ->where('allocated', ['direct_labor', 'manufacturing_overheads'])
            ->has('months', 48)                         // January 2026 to December 2029 (the study's last year + the extra year)
            ->where('months.0.index', 0)
            ->where('months.47.year', 2029)
            ->has('products', 2)
            ->where('project.endYear', 2028)
            ->where('readOnly', false));
    }

    public function test_staff_are_saved_and_the_cost_is_worked_out_and_given_to_the_products(): void
    {
        $project = $this->study();

        $this->actingAs($this->owner)->put(route('projects.manpower.update', $project), $this->staff())
            ->assertSessionHasNoErrors()->assertRedirect(route('projects.manpower.edit', $project));

        $row = Manpower::where('project_id', $project->id)->first();
        $this->assertSame('Welder', $row->position);
        $this->assertEquals(3, $row->accumulated_manpower_counts[0]);
        $this->assertEqualsWithDelta(3000, $row->salary_payments[0], 0.001);
        $this->assertEqualsWithDelta(3000 / 0.9, $row->salary_expenses[0], 0.001);
        $this->assertEqualsWithDelta(3300 / 0.9, $row->salary_expenses[12], 0.001);   // 10 % more after 12 months of operation
        $this->assertEqualsWithDelta(3000 / 0.9 * 0.1, $row->tax_and_social_insurance_statement['monthly']['expense'][0], 0.001);
        $this->assertEquals([0 => 1.0], $row->hirings);

        // Shared by sales: only Valve sells, so Valve carries all of it.
        $valve = $project->products()->where('name', 'Valve')->first();
        $pump = $project->products()->where('name', 'Pump')->first();
        $this->assertEqualsWithDelta(3000 / 0.9, $valve->product_manpower_allocation[0], 0.001);
        $this->assertEqualsWithDelta(0, $pump->product_manpower_allocation[0], 0.001);
        $this->assertSame(10.0, (float) $project->refresh()->salaries_annual_increase);
    }

    public function test_the_cost_can_be_shared_by_typed_percentages(): void
    {
        $project = $this->study();
        $valve = $project->products()->where('name', 'Valve')->first();
        $pump = $project->products()->where('name', 'Pump')->first();

        $this->actingAs($this->owner)->put(route('projects.manpower.update', $project), $this->staff(null, [
            'by_revenue'  => ['direct_labor' => false],
            'allocations' => ['direct_labor' => [$valve->id => 70, $pump->id => 30]],
        ]))->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(3000 / 0.9 * 0.7, $valve->refresh()->product_manpower_allocation[0], 0.001);
        $this->assertEqualsWithDelta(3000 / 0.9 * 0.3, $pump->refresh()->product_manpower_allocation[0], 0.001);

        $this->actingAs($this->owner)->get(route('projects.manpower.edit', $project))->assertInertia(fn (Assert $page) => $page
            ->where('byRevenue.direct_labor', false)
            ->where("allocations.direct_labor.{$valve->id}", fn ($v) => (float) $v === 70.0)
            ->has('rows', 1)
            ->where('rows.0.position', 'Welder')
            ->where('results.years', [2026, 2027, 2028, 2029]));
    }

    public function test_overheads_staff_are_also_listed_as_indirect_manpower_of_each_product(): void
    {
        $project = $this->study();

        $this->actingAs($this->owner)->put(route('projects.manpower.update', $project), $this->staff([
            ['type' => 'manufacturing_overheads', 'position' => 'Supervisor', 'avg_salary' => 2000, 'existing_count' => 1, 'hirings' => []],
        ]))->assertSessionHasNoErrors();

        $rows = ProductExpenseAllocation::where('project_id', $project->id)->where('is_indirect_manpower', 1)->get();
        $this->assertCount(2, $rows);
        $this->assertSame('manufacturing_overheads', $rows->first()->expense_name);
        $this->assertFalse($rows->first()->is_expense);
        $this->assertEqualsWithDelta(2000 / 0.9, $project->products()->where('name', 'Valve')->first()->product_overheads_allocation[0], 0.001);
    }

    public function test_a_position_that_is_no_longer_sent_is_deleted(): void
    {
        $project = $this->study();
        $this->actingAs($this->owner)->put(route('projects.manpower.update', $project), $this->staff())->assertSessionHasNoErrors();
        $this->assertEquals(1, Manpower::where('project_id', $project->id)->count());

        $this->actingAs($this->owner)->put(route('projects.manpower.update', $project), $this->staff([], ['salaries_tax_rate' => 4]))->assertSessionHasNoErrors();
        $this->assertEquals(0, Manpower::where('project_id', $project->id)->count());
    }

    public function test_a_new_company_starts_with_no_staff(): void
    {
        $project = $this->study(true);

        $this->actingAs($this->owner)->put(route('projects.manpower.update', $project), $this->staff())->assertSessionHasNoErrors();

        $row = Manpower::where('project_id', $project->id)->first();
        $this->assertEquals(0, (float) $row->existing_count);
        $this->assertEquals(1, $row->accumulated_manpower_counts[0]);   // only the one person hired in month 0
    }

    public function test_staff_that_are_not_valid_are_refused(): void
    {
        $project = $this->study();
        $send = fn (array $payload) => $this->actingAs($this->owner)->put(route('projects.manpower.update', $project), $payload);

        $send($this->staff(null, ['salaries_tax_rate' => 60, 'social_insurance_rate' => 40]))->assertSessionHasErrors('salaries_tax_rate');
        $send($this->staff([['type' => 'pilot', 'position' => 'X', 'avg_salary' => 1, 'existing_count' => 0, 'hirings' => []]]))->assertSessionHasErrors('rows.0.type');
        $send($this->staff([['type' => 'sales', 'position' => '', 'avg_salary' => 1, 'existing_count' => 0, 'hirings' => []]]))->assertSessionHasErrors('rows.0.position');
        $send($this->staff([['type' => 'sales', 'position' => 'Rep', 'avg_salary' => -5, 'existing_count' => 0, 'hirings' => []]]))->assertSessionHasErrors('rows.0.avg_salary');
        $send($this->staff([['type' => 'sales', 'position' => 'Rep', 'avg_salary' => 5, 'existing_count' => 0, 'hirings' => [999 => 1]]]))->assertSessionHasErrors('rows.0.hirings');
        $send($this->staff([['id' => 987654, 'type' => 'sales', 'position' => 'Rep', 'avg_salary' => 5, 'existing_count' => 0, 'hirings' => []]]))->assertSessionHasErrors('rows.0.id');
        $send($this->staff(null, ['by_revenue' => ['direct_labor' => false], 'allocations' => ['direct_labor' => [1 => 60]]]))->assertSessionHasErrors('allocations.direct_labor');

        $this->assertEquals(0, Manpower::where('project_id', $project->id)->count());
    }

    // ── Expenses ────────────────────────────────────────────────────────────

    public function test_the_expenses_page_offers_the_categories_of_the_study(): void
    {
        $existing = $this->study();
        $this->actingAs($this->owner)->get(route('projects.expenses.edit', $existing))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Expenses/Edit')
            ->has('categories', 5)
            ->where('categories.0.id', 'manufacturing-expenses')->where('categories.0.allocated', true)
            ->where('categories.2.allocated', false)
            ->has('kinds', 4)->has('products', 2)->where('project.endYear', 2028));

        $this->owner = User::factory()->create();
        $new = $this->study(true);
        $this->actingAs($this->owner)->get(route('projects.expenses.edit', $new))->assertInertia(fn (Assert $page) => $page->has('categories', 7));
    }

    public function test_a_fixed_monthly_expense_is_saved_as_positions_and_worked_out_to_the_extended_year(): void
    {
        $project = $this->study();

        $this->actingAs($this->owner)->put(route('projects.expenses.update', $project), $this->expenses([$this->fixed()]))
            ->assertSessionHasNoErrors()->assertRedirect(route('projects.expenses.edit', $project));

        $expense = Expense::where('project_id', $project->id)->first();
        $this->assertEquals(0, $expense->start_date);
        $this->assertEquals(35, $expense->end_date);                 // December 2028 = position 35
        $this->assertEqualsWithDelta(10000, $expense->monthly_repeating_amounts[0], 0.001);
        $this->assertEqualsWithDelta(11000, $expense->monthly_repeating_amounts[12], 0.001);
        $this->assertArrayHasKey(47, $expense->monthly_repeating_amounts);   // runs on through the extra year (December 2029)
        $this->assertArrayNotHasKey(48, $expense->monthly_repeating_amounts);
        $this->assertEqualsWithDelta(10000, $expense->payment_amounts[0], 0.001);
        $this->assertEqualsWithDelta(10000, $expense->collection_statements['monthly']['expense'][0], 0.001);

        $this->actingAs($this->owner)->get(route('projects.expenses.edit', $project))->assertInertia(fn (Assert $page) => $page
            ->where('rows.0.start', '2026-01')->where('rows.0.end', '2028-12')->where('rows.0.name', 'Rent')
            ->has('rows.0.custom', 1)
            ->where('results.years', [2026, 2027, 2028, 2029]));
    }

    public function test_moving_the_study_start_moves_the_expense_months_with_it(): void
    {
        $project = $this->study();
        $this->actingAs($this->owner)->put(route('projects.expenses.update', $project), $this->expenses([$this->fixed(['start' => '2026-04', 'end' => '2026-09'])]))->assertSessionHasNoErrors();
        $this->assertEquals(3, Expense::where('project_id', $project->id)->first()->start_date);

        // The study (and operation, and selling) now start in March: the expense is still at position 3, so it is June now.
        $valve = $project->products()->where('name', 'Valve')->first();
        $pump = $project->products()->where('name', 'Pump')->first();
        $this->actingAs($this->owner)->put(route('projects.update', $project), [
            'name' => 'Plant A', 'new_company' => false, 'main_functional_currency' => 'egp', 'intro_paragraph' => '', 'business_sector_id' => null,
            'start_date' => '2026-03', 'duration' => 3, 'operation_start_date' => '2026-03', 'tax_rate' => 22.5, 'return_rate' => 15, 'perpetual_growth_rate' => 3,
            'products' => [
                ['id' => $valve->id, 'name' => 'Valve', 'measurement_unit' => 'piece', 'selling_start_date' => '2026-03', 'vat_rate' => 14, 'withhold_tax_rate' => 1],
                ['id' => $pump->id, 'name' => 'Pump', 'measurement_unit' => 'piece', 'selling_start_date' => '2026-03', 'vat_rate' => 14, 'withhold_tax_rate' => 1],
            ],
            'rawMaterials' => [['id' => $project->rawMaterials()->value('id'), 'name' => 'Brass', 'rm_inventory_coverage_days' => 0, 'measurement_unit' => 'kg', 'vat_rate' => 14, 'withhold_tax_rate' => 0]],
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->owner)->get(route('projects.expenses.edit', $project))->assertInertia(fn (Assert $page) => $page
            ->where('rows.0.start', '2026-06')->where('rows.0.end', '2026-11'));
    }

    public function test_a_one_time_expense_is_paid_at_once_and_spread_over_months(): void
    {
        $project = $this->study();

        $this->actingAs($this->owner)->put(route('projects.expenses.update', $project), $this->expenses([[
            'relation_name' => 'one_time_expense', 'category_id' => 'general-expenses', 'name' => 'Licence', 'amount' => 1200, 'amortization_months' => 12,
            'start' => '2026-03', 'payment_terms' => 'cash',
        ]]))->assertSessionHasNoErrors();

        $expense = Expense::where('project_id', $project->id)->first();
        $this->assertEquals(2, $expense->start_date);
        $this->assertEquals(2, $expense->end_date);
        $this->assertEqualsWithDelta(100, $expense->payload['monthly_one_time'][2], 0.001);
        $this->assertEqualsWithDelta(1200, $expense->payment_amounts[2], 0.001);
        $this->assertEqualsWithDelta(1200, $expense->collection_statements['monthly']['expense'][2], 0.001);
        $this->assertEquals(0, ProductExpenseAllocation::where('expense_name', 'Licence')->count());   // general expenses are not shared between products
    }

    public function test_a_percentage_of_sales_and_a_cost_per_unit_use_the_chosen_products(): void
    {
        $project = $this->study();
        $valve = $project->products()->where('name', 'Valve')->first();

        $this->actingAs($this->owner)->put(route('projects.expenses.update', $project), $this->expenses([
            ['relation_name' => 'expense_as_percentage', 'category_id' => 'marketing', 'name' => 'Commission', 'monthly_percentage' => 10, 'products' => [$valve->id],
                'start' => '2026-01', 'end' => '2026-12', 'payment_terms' => 'cash'],
            ['relation_name' => 'cost_per_unit', 'category_id' => 'manufacturing-expenses', 'name' => 'Packing', 'monthly_cost_of_unit' => 2, 'products' => [$valve->id],
                'start' => '2026-01', 'end' => '2026-12', 'payment_terms' => 'quarterly'],
        ]))->assertSessionHasNoErrors();

        $commission = Expense::where('name', 'Commission')->first();
        $this->assertEqualsWithDelta(100, $commission->expense_as_percentages[0], 0.001);      // 10 % of 1000
        $this->assertEqualsWithDelta(100, $commission->expense_as_percentages[11], 0.001);
        $this->assertArrayNotHasKey(12, $commission->expense_as_percentages);

        $packing = Expense::where('name', 'Packing')->first();
        $this->assertEqualsWithDelta(200, $packing->expense_as_percentages[0], 0.001);         // 100 units x 2
        $this->assertEqualsWithDelta(600, $packing->payment_amounts[0], 0.001);                // the first quarter, paid in its first month
        $this->assertEqualsWithDelta(0, $packing->payment_amounts[1] ?? 0, 0.001);

        $this->assertEquals(0, ProductExpenseAllocation::where('expense_name', 'Commission')->count());
        $allocations = ProductExpenseAllocation::where('expense_name', 'Packing')->get();
        $this->assertCount(1, $allocations);
        $this->assertEquals($valve->id, $allocations->first()->product_id);
        $this->assertTrue($allocations->first()->is_expense);
    }

    public function test_a_fixed_expense_is_shared_between_the_products_by_sales_or_by_typed_percentages(): void
    {
        $project = $this->study();
        $valve = $project->products()->where('name', 'Valve')->first();
        $pump = $project->products()->where('name', 'Pump')->first();

        $this->actingAs($this->owner)->put(route('projects.expenses.update', $project), $this->expenses([$this->fixed()]))->assertSessionHasNoErrors();

        $shares = ProductExpenseAllocation::where('project_id', $project->id)->where('expense_name', 'Rent')->get()->keyBy('product_id');
        $this->assertCount(2, $shares);
        $this->assertEqualsWithDelta(10000, $shares[$valve->id]->payload[0], 0.001);
        $this->assertEqualsWithDelta(0, $shares[$pump->id]->payload[0], 0.001);

        $id = Expense::where('project_id', $project->id)->value('id');
        $this->actingAs($this->owner)->put(route('projects.expenses.update', $project), $this->expenses([
            $this->fixed(['id' => $id, 'is_as_revenue_percentages' => false, 'allocations' => [$valve->id => 25, $pump->id => 75]]),
        ]))->assertSessionHasNoErrors();

        $shares = ProductExpenseAllocation::where('project_id', $project->id)->where('expense_name', 'Rent')->get()->keyBy('product_id');
        $this->assertCount(2, $shares);                                  // the old rows were replaced, not added to
        $this->assertEqualsWithDelta(2500, $shares[$valve->id]->payload[0], 0.001);
        $this->assertEqualsWithDelta(7500, $shares[$pump->id]->payload[0], 0.001);
    }

    public function test_customised_payments_are_split_over_the_months(): void
    {
        $project = $this->study();

        $this->actingAs($this->owner)->put(route('projects.expenses.update', $project), $this->expenses([$this->fixed([
            'increase_rate' => 0, 'payment_terms' => 'customize',
            'custom' => [['due_days' => 0, 'rate' => 60], ['due_days' => 30, 'rate' => 40], ['due_days' => '', 'rate' => ''], ['due_days' => '', 'rate' => ''], ['due_days' => '', 'rate' => '']],
        ])]))->assertSessionHasNoErrors();

        $expense = Expense::where('project_id', $project->id)->first();
        $this->assertEqualsWithDelta(6000, $expense->payment_amounts[0], 0.001);
        $this->assertEqualsWithDelta(4000 + 6000, $expense->payment_amounts[1], 0.001);        // 40 % of January + 60 % of February
        $this->assertEqualsWithDelta(4000, $expense->collection_statements['monthly']['end_balance'][0], 0.001);
        $this->assertEquals([0 => 60.0, 30 => 40.0], $expense->custom_collection_policy);
    }

    public function test_deleting_an_expense_removes_its_product_shares(): void
    {
        $project = $this->study();
        $this->actingAs($this->owner)->put(route('projects.expenses.update', $project), $this->expenses([$this->fixed()]))->assertSessionHasNoErrors();
        $this->assertEquals(2, ProductExpenseAllocation::where('project_id', $project->id)->where('is_expense', 1)->count());

        $this->actingAs($this->owner)->put(route('projects.expenses.update', $project), $this->expenses([]))->assertSessionHasNoErrors();
        $this->assertEquals(0, Expense::where('project_id', $project->id)->count());
        $this->assertEquals(0, ProductExpenseAllocation::where('project_id', $project->id)->where('is_expense', 1)->count());
    }

    public function test_expenses_that_are_not_valid_are_refused(): void
    {
        $project = $this->study();
        $valve = $project->products()->where('name', 'Valve')->first();
        $send = fn (array $row) => $this->actingAs($this->owner)->put(route('projects.expenses.update', $project), $this->expenses([$row]));

        $send($this->fixed(['name' => '']))->assertSessionHasErrors('rows.0.name');
        $send($this->fixed(['relation_name' => 'magic']))->assertSessionHasErrors('rows.0.relation_name');
        $send($this->fixed(['category_id' => 'pre-operating-expenses']))->assertSessionHasErrors('rows.0.category_id');   // new companies only
        $send($this->fixed(['amount' => -1]))->assertSessionHasErrors('rows.0.amount');
        $send($this->fixed(['start' => '2035-01']))->assertSessionHasErrors('rows.0.start');                                  // outside the study
        $send($this->fixed(['start' => '2027-05', 'end' => '2027-01']))->assertSessionHasErrors('rows.0.end');
        $send($this->fixed(['end' => '']))->assertSessionHasErrors('rows.0.end');
        $send($this->fixed(['payment_terms' => 'barter']))->assertSessionHasErrors('rows.0.payment_terms');
        $send($this->fixed(['payment_terms' => 'customize', 'custom' => [['due_days' => 0, 'rate' => 50], ['due_days' => 45, 'rate' => 40]]]))->assertSessionHasErrors('rows.0.custom');
        $send($this->fixed(['payment_terms' => 'customize', 'custom' => [['due_days' => 7, 'rate' => 100]]]))->assertSessionHasErrors('rows.0.custom.0.due_days');
        $send($this->fixed(['is_as_revenue_percentages' => false, 'allocations' => [$valve->id => 50]]))->assertSessionHasErrors('rows.0.allocations');
        $send(['relation_name' => 'expense_as_percentage', 'category_id' => 'marketing', 'name' => 'Commission', 'monthly_percentage' => 10, 'products' => [],
            'start' => '2026-01', 'end' => '2026-12', 'payment_terms' => 'cash'])->assertSessionHasErrors('rows.0.products');
        $send(['relation_name' => 'expense_as_percentage', 'category_id' => 'marketing', 'name' => 'Commission', 'monthly_percentage' => 150, 'products' => [$valve->id],
            'start' => '2026-01', 'end' => '2026-12', 'payment_terms' => 'cash'])->assertSessionHasErrors('rows.0.monthly_percentage');
        $send(['id' => 987654] + $this->fixed())->assertSessionHasErrors('rows.0.id');

        $this->assertEquals(0, Expense::where('project_id', $project->id)->count());
    }

    public function test_a_new_company_may_have_pre_operating_and_start_up_expenses(): void
    {
        $project = $this->study(true);

        $this->actingAs($this->owner)->put(route('projects.expenses.update', $project), $this->expenses([
            ['relation_name' => 'one_time_expense', 'category_id' => 'pre-operating-expenses', 'name' => 'Feasibility', 'amount' => 5000, 'amortization_months' => 6, 'start' => '2026-01', 'payment_terms' => 'cash'],
            ['relation_name' => 'one_time_expense', 'category_id' => 'start-up-expenses', 'name' => 'Launch', 'amount' => 2000, 'amortization_months' => 6, 'start' => '2026-02', 'payment_terms' => 'cash'],
        ]))->assertSessionHasNoErrors();

        $this->assertEquals(2, Expense::where('project_id', $project->id)->count());
    }

    public function test_the_calculation_hooks_are_reached_from_the_other_saves(): void
    {
        // Saving a product afterwards recalculates the staff and expenses too (their shares follow the new sales).
        $project = $this->study();
        $this->actingAs($this->owner)->put(route('projects.manpower.update', $project), $this->staff())->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->put(route('projects.expenses.update', $project), $this->expenses([$this->fixed()]))->assertSessionHasNoErrors();

        $pump = $project->products()->where('name', 'Pump')->first();
        $cash = array_fill(0, 2, ['cash_payment' => 100, 'rate' => [0, 0], 'due_in_days' => [30, 60]]);
        $this->actingAs($this->owner)->put(route('projects.products.update', [$project, $pump]), [
            'max_capacity' => [0 => 1200, 1 => 1200, 2 => 1200], 'target_percentages' => [0 => 100, 1 => 100, 2 => 100],
            'local_target_quantity_percentages' => [0 => 100, 1 => 100, 2 => 100], 'local_growth_rates' => [0 => 0, 1 => 0, 2 => 0],
            'local_price_per_unit' => [0 => 10, 1 => 10, 2 => 10], 'export_growth_rates' => [0 => 0, 1 => 0, 2 => 0], 'export_price_per_unit' => [0 => 0, 1 => 0, 2 => 0],
            'seasonality' => ['type' => 'flat'], 'fg_inventory_coverage_days' => 0, 'fg_inventory_quantity' => 0, 'fg_inventory_value' => 0,
            'collection_policy_value' => ['local' => $cash, 'export' => $cash],
        ])->assertSessionHasNoErrors();

        // Both products now sell the same, so each carries half.
        $this->assertEqualsWithDelta(3000 / 0.9 / 2, $pump->refresh()->product_manpower_allocation[0], 0.001);
        $this->assertEqualsWithDelta(5000, ProductExpenseAllocation::where('expense_name', 'Rent')->where('product_id', $pump->id)->first()->payload[0], 0.001);
    }

    public function test_a_copy_of_the_study_keeps_staff_and_expenses_with_shares_pointing_at_the_copied_products(): void
    {
        $project = $this->study();
        $valve = $project->products()->where('name', 'Valve')->first();
        $pump = $project->products()->where('name', 'Pump')->first();

        $this->actingAs($this->owner)->put(route('projects.manpower.update', $project), $this->staff(null, [
            'by_revenue' => ['direct_labor' => false], 'allocations' => ['direct_labor' => [$valve->id => 70, $pump->id => 30]],
        ]))->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->put(route('projects.expenses.update', $project), $this->expenses([
            $this->fixed(['is_as_revenue_percentages' => false, 'allocations' => [$valve->id => 25, $pump->id => 75]]),
        ]))->assertSessionHasNoErrors();

        $this->actingAs($this->owner)->post(route('projects.copy', $project), ['name' => 'Plant A copy'])->assertSessionHasNoErrors();
        $copy = Project::where('name', 'Plant A copy')->firstOrFail();
        $copyValve = $copy->products()->where('name', 'Valve')->first();

        $this->assertSame(1, Manpower::where('project_id', $copy->id)->count());
        $this->assertSame(1, Expense::where('project_id', $copy->id)->count());

        $this->actingAs($this->owner)->get(route('projects.manpower.edit', $copy))->assertInertia(fn (Assert $page) => $page
            ->where("allocations.direct_labor.{$copyValve->id}", fn ($v) => (float) $v === 70.0)->has('rows', 1));
        $this->actingAs($this->owner)->get(route('projects.expenses.edit', $copy))->assertInertia(fn (Assert $page) => $page
            ->where("rows.0.allocations.{$copyValve->id}", fn ($v) => (float) $v === 25.0)->where('rows.0.is_as_revenue_percentages', false));
    }
}
