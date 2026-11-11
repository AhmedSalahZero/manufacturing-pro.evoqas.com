<?php

namespace Tests\Feature;

use App\Models\FixedAsset;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

// The fixed assets form: saving, checks, access and the numbers that come out.
// Run:  php artisan test --filter=FixedAssetsTest
class FixedAssetsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
    }

    /** A 3-year study from 2026-01 of an existing company with two products; Valve sells 1000 a month. */
    private function study(): Project
    {
        $project = app(ProjectService::class)->create($this->owner, 'Plant A');

        $this->actingAs($this->owner)->put(route('projects.update', $project), [
            'name' => 'Plant A', 'new_company' => false, 'main_functional_currency' => 'egp', 'intro_paragraph' => '', 'business_sector_id' => null,
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

    private function asset(array $over = []): array
    {
        return array_merge([
            'name' => 'Press', 'counts' => 2, 'amount' => 6000, 'depreciation_duration' => 5, 'start' => '2026-01', 'end' => '2026-01',
            'admin_depreciation_percentage' => 0, 'replacement_cost_rate' => 0, 'replacement_cost_interval' => 1,
            'payment_terms' => 'cash', 'equity_funding_rate' => 100, 'interest_rate' => 0, 'grace_period' => 0, 'tenor' => 60, 'installment_interval' => 'monthly',
            'is_as_revenue_percentages' => true,
        ], $over);
    }

    private function body(array $rows, string $after = 'stay'): array
    {
        return ['rows' => $rows, 'after' => $after];
    }

    // ── Access ──────────────────────────────────────────────────────────────

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $project = $this->study();
        $this->app['auth']->forgetGuards();

        $this->get(route('projects.assets.edit', $project))->assertRedirect('/login');
    }

    public function test_a_stranger_cannot_look_or_save(): void
    {
        $project = $this->study();
        $stranger = User::factory()->companyAdmin()->create();

        $this->actingAs($stranger)->get(route('projects.assets.edit', $project))->assertForbidden();
        $this->actingAs($stranger)->put(route('projects.assets.update', $project), $this->body([$this->asset()]))->assertForbidden();
    }

    // ── Saving ──────────────────────────────────────────────────────────────

    public function test_the_form_opens_with_the_study_products_and_no_assets(): void
    {
        $project = $this->study();

        $this->actingAs($this->owner)->get(route('projects.assets.edit', $project))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('FixedAssets/Edit')
                ->where('project.startYear', 2026)
                ->has('products', 2)
                ->has('rows', 0)
                ->where('readOnly', false));
    }

    public function test_a_cash_asset_is_saved_and_depreciated_into_the_products(): void
    {
        $project = $this->study();

        $this->actingAs($this->owner)->put(route('projects.assets.update', $project), $this->body([$this->asset()]))
            ->assertSessionHasNoErrors()->assertRedirect(route('projects.assets.edit', $project));

        $asset = FixedAsset::where('project_id', $project->id)->firstOrFail();
        $this->assertEquals(0, $asset->start_date);
        $this->assertEqualsWithDelta(12000.0, (float) array_sum((array) $asset->ffe_payment), 0.01);
        $this->assertSame(0, DB::table('loan_schedule_payments')->where('project_id', $project->id)->count());
        $this->assertSame(2, DB::table('product_expense_allocations')->where('project_id', $project->id)->where('is_depreciation', 1)->count());
        $shares = DB::table('product_expense_allocations')->where('project_id', $project->id)->where('is_depreciation', 1)->pluck('payload')
            ->sum(fn ($j) => array_sum(array_map('floatval', (array) json_decode($j, true))));
        $this->assertGreaterThan(0, $shares);   // 200 a month for the months inside the study
        $this->assertLessThanOrEqual(12000.01, $shares);
    }

    public function test_a_part_loan_creates_a_repayment_schedule(): void
    {
        $project = $this->study();

        $this->actingAs($this->owner)->put(route('projects.assets.update', $project), $this->body([
            $this->asset(['equity_funding_rate' => 50, 'interest_rate' => 12, 'tenor' => 24, 'grace_period' => 3, 'installment_interval' => 'quarterly']),
        ]))->assertSessionHasNoErrors();

        $this->assertSame(1, DB::table('loan_schedule_payments')->where('project_id', $project->id)->count());
        $asset = FixedAsset::where('project_id', $project->id)->firstOrFail();
        $this->assertEqualsWithDelta(6000.0, (float) array_sum((array) $asset->ffe_loan_withdrawal), 0.01);
    }

    public function test_the_overhead_roll_up_includes_the_depreciation(): void
    {
        $project = $this->study();

        $this->actingAs($this->owner)->put(route('projects.assets.update', $project), $this->body([$this->asset(['name' => 'Press roll'])]))->assertSessionHasNoErrors();

        $total = (float) DB::table('products')->where('project_id', $project->id)->get()->sum(fn ($p) => array_sum((array) json_decode($p->product_depreciation_allocation ?? '[]', true)));
        $this->assertGreaterThan(0, $total);
    }

    public function test_rows_not_sent_again_are_deleted_with_their_numbers(): void
    {
        $project = $this->study();
        $this->actingAs($this->owner)->put(route('projects.assets.update', $project), $this->body([$this->asset(['name' => 'Press delete'])]))->assertSessionHasNoErrors();
        $this->assertSame(1, FixedAsset::where('project_id', $project->id)->count());

        $this->actingAs($this->owner)->put(route('projects.assets.update', $project), $this->body([], 'list'))->assertRedirect(route('projects.index'));

        $this->assertSame(0, FixedAsset::where('project_id', $project->id)->count());
        $this->assertSame(0, DB::table('product_expense_allocations')->where('project_id', $project->id)->where('is_depreciation', 1)->count());
    }

    // ── Checks ──────────────────────────────────────────────────────────────

    public function test_customised_payments_must_total_100(): void
    {
        $project = $this->study();

        $this->actingAs($this->owner)->put(route('projects.assets.update', $project), $this->body([
            $this->asset(['name' => 'Press custom', 'payment_terms' => 'customize', 'custom' => [['due_days' => 0, 'rate' => 40, 'from_total' => false], ['due_days' => 30, 'rate' => 40, 'from_total' => false]]]),
        ]))->assertSessionHasErrors(['rows.0.custom']);
    }

    // ── Kinds of asset: land, construction, machine, other ──────────────────

    public function test_land_is_bought_in_one_month_and_is_not_depreciated(): void
    {
        $project = $this->study();

        // The end month and the depreciation sent with it are ignored for land.
        $this->actingAs($this->owner)->put(route('projects.assets.update', $project), $this->body([
            $this->asset(['name' => 'Plot', 'asset_type' => 'land', 'start' => '2026-03', 'end' => '2026-08', 'depreciation_duration' => 5, 'replacement_cost_rate' => 10]),
        ]))->assertSessionHasNoErrors();

        $asset = FixedAsset::where('project_id', $project->id)->firstOrFail();
        $this->assertSame('land', $asset->asset_type);
        $this->assertEquals(2, $asset->start_date);
        $this->assertEquals(2, $asset->end_date);
        $this->assertEquals(0, $asset->depreciation_duration);
        $this->assertEqualsWithDelta(12000.0, (float) ((array) $asset->ffe_payment)[2], 0.01);
        $this->assertSame(0, DB::table('product_expense_allocations')->where('project_id', $project->id)->where('is_depreciation', 1)->count());
    }

    public function test_other_assets_have_one_month_and_are_depreciated(): void
    {
        $project = $this->study();

        $this->actingAs($this->owner)->put(route('projects.assets.update', $project), $this->body([
            $this->asset(['name' => 'Forklift', 'asset_type' => 'other', 'start' => '2026-03', 'end' => '', 'depreciation_duration' => 5]),
        ]))->assertSessionHasNoErrors();

        $asset = FixedAsset::where('project_id', $project->id)->firstOrFail();
        $this->assertSame('other', $asset->asset_type);
        $this->assertEquals(2, $asset->end_date);
        $this->assertSame(2, DB::table('product_expense_allocations')->where('project_id', $project->id)->where('is_depreciation', 1)->count());
    }

    public function test_a_construction_can_be_spent_on_an_s_curve(): void
    {
        $project = $this->study();

        $this->actingAs($this->owner)->put(route('projects.assets.update', $project), $this->body([
            $this->asset(['name' => 'Hall', 'asset_type' => 'construction', 'start' => '2026-01', 'end' => '2026-04', 'execution_curve' => 's_curve']),
        ]))->assertSessionHasNoErrors();

        $asset = FixedAsset::where('project_id', $project->id)->firstOrFail();
        $spent = (array) $asset->ffe_execution_and_payment;
        $this->assertSame('s_curve', $asset->execution_curve);
        $this->assertCount(4, $spent);
        $this->assertLessThan($spent[1], $spent[0]);   // slow start, faster in the middle
        $this->assertEqualsWithDelta(12000.0, (float) array_sum($spent), 0.01);
    }

    public function test_a_construction_pays_a_down_payment_on_the_total_and_the_rest_after_each_months_work(): void
    {
        $project = $this->study();

        // 12 000 built over 10 months (1 200 a month): 25 % down payment on the total, 50 % of each month's work after 30 days, 25 % after 60 days.
        $this->actingAs($this->owner)->put(route('projects.assets.update', $project), $this->body([
            $this->asset(['name' => 'Factory hall', 'asset_type' => 'construction', 'start' => '2026-01', 'end' => '2026-10', 'payment_terms' => 'customize', 'custom' => [
                ['due_days' => 0, 'rate' => 25, 'from_total' => true],
                ['due_days' => 30, 'rate' => 50, 'from_total' => false],
                ['due_days' => 60, 'rate' => 25, 'from_total' => false],
            ]]),
        ]))->assertSessionHasNoErrors();

        $asset = FixedAsset::where('project_id', $project->id)->firstOrFail();
        $paid = (array) $asset->ffe_payment;
        $this->assertEqualsWithDelta(3000.0, (float) $paid[0], 0.01);    // the down payment
        $this->assertEqualsWithDelta(600.0, (float) $paid[1], 0.01);     // 50 % of the first month's work
        $this->assertEqualsWithDelta(900.0, (float) $paid[2], 0.01);
        $this->assertEqualsWithDelta(12000.0, (float) array_sum($paid), 0.01);
        $this->assertEquals([0 => 1, 30 => 0, 60 => 0], array_map('intval', (array) $asset->from_total_or_executions));   // each line keeps its own choice
    }

    /** The production line: bought in January, delivered in July, ready in September; 50 % at the order, 30 % at delivery, 20 % when ready. */
    private function productionLine(array $over = []): array
    {
        return $this->asset(array_merge([
            'name' => 'Production line', 'asset_type' => 'machine', 'start' => '2026-01', 'delivery' => '2026-07', 'end' => '2026-09',
            'payment_terms' => 'milestone',
            'milestones' => ['purchase' => ['rate' => 50, 'days' => 0], 'delivery' => ['rate' => 30, 'days' => 0], 'ready' => ['rate' => 20, 'days' => 0]],
        ], $over));
    }

    public function test_a_machine_is_saved_with_its_delivery_month_and_paid_by_milestones(): void
    {
        $project = $this->study();

        $this->actingAs($this->owner)->put(route('projects.assets.update', $project), $this->body([$this->productionLine()]))->assertSessionHasNoErrors();

        $asset = FixedAsset::where('project_id', $project->id)->firstOrFail();
        $this->assertSame('machine', $asset->asset_type);
        $this->assertEquals(0, $asset->start_date);
        $this->assertEquals(6, $asset->delivery_date);
        $this->assertEquals(8, $asset->end_date);

        $paid = (array) $asset->ffe_payment;
        $this->assertEqualsWithDelta(6000.0, (float) $paid[0], 0.01);
        $this->assertEqualsWithDelta(3600.0, (float) $paid[6], 0.01);
        $this->assertEqualsWithDelta(2400.0, (float) $paid[8], 0.01);

        $owed = (array) $asset->ffe_payable;
        $this->assertEqualsWithDelta(0.0, (float) $owed[5], 0.01);       // nothing is owed before the delivery
        $this->assertEqualsWithDelta(2400.0, (float) $owed[6], 0.01);    // after the delivery the last milestone is still owed
        $this->assertEqualsWithDelta(0.0, (float) $owed[8], 0.01);

        $this->actingAs($this->owner)->get(route('projects.assets.edit', $project))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('rows.0.asset_type', 'machine')
                ->where('rows.0.start', '2026-01')
                ->where('rows.0.delivery', '2026-07')
                ->where('rows.0.end', '2026-09')
                ->where('rows.0.milestones.delivery.days', 0)
                ->where('rows.0.payment_terms', 'milestone'));
    }

    public function test_milestones_must_add_up_to_100(): void
    {
        $project = $this->study();

        $this->actingAs($this->owner)->put(route('projects.assets.update', $project), $this->body([
            $this->productionLine(['milestones' => ['purchase' => ['rate' => 50, 'days' => 0], 'delivery' => ['rate' => 30, 'days' => 0], 'ready' => ['rate' => 10, 'days' => 0]]]),
        ]))->assertSessionHasErrors(['rows.0.milestones']);
    }

    public function test_only_a_machine_can_be_paid_by_milestones(): void
    {
        $project = $this->study();

        $this->actingAs($this->owner)->put(route('projects.assets.update', $project), $this->body([
            $this->productionLine(['asset_type' => 'construction']),
        ]))->assertSessionHasErrors(['rows.0.payment_terms']);
    }

    public function test_the_delivery_must_fall_between_the_purchase_and_the_ready_month(): void
    {
        $project = $this->study();

        $this->actingAs($this->owner)->put(route('projects.assets.update', $project), $this->body([
            $this->productionLine(['delivery' => '2026-10', 'end' => '2026-09']),
        ]))->assertSessionHasErrors(['rows.0.delivery']);

        $this->actingAs($this->owner)->put(route('projects.assets.update', $project), $this->body([
            $this->productionLine(['start' => '2026-05', 'delivery' => '2026-03', 'end' => '2026-09']),
        ]))->assertSessionHasErrors(['rows.0.delivery']);
    }

    public function test_a_machine_needs_a_delivery_month_and_a_construction_needs_an_end_month(): void
    {
        $project = $this->study();

        $this->actingAs($this->owner)->put(route('projects.assets.update', $project), $this->body([
            $this->productionLine(['delivery' => '']),
        ]))->assertSessionHasErrors(['rows.0.delivery']);

        $this->actingAs($this->owner)->put(route('projects.assets.update', $project), $this->body([
            $this->asset(['asset_type' => 'construction', 'end' => '']),
        ]))->assertSessionHasErrors(['rows.0.end']);
    }

    public function test_the_end_month_cannot_be_before_the_start_month(): void
    {
        $project = $this->study();

        $this->actingAs($this->owner)->put(route('projects.assets.update', $project), $this->body([
            $this->asset(['name' => 'Press dates', 'start' => '2026-06', 'end' => '2026-02']),
        ]))->assertSessionHasErrors(['rows.0.end']);
    }

    public function test_the_grace_period_must_fit_the_loan_tenor(): void
    {
        $project = $this->study();

        $this->actingAs($this->owner)->put(route('projects.assets.update', $project), $this->body([
            $this->asset(['name' => 'Press grace', 'equity_funding_rate' => 50, 'interest_rate' => 10, 'tenor' => 12, 'grace_period' => 18]),
        ]))->assertSessionHasErrors(['rows.0.grace_period']);
    }

    public function test_typed_product_shares_must_total_100(): void
    {
        $project = $this->study();
        [$a, $b] = $project->products()->orderBy('id')->pluck('id')->all();

        $this->actingAs($this->owner)->put(route('projects.assets.update', $project), $this->body([
            $this->asset(['name' => 'Press shares', 'is_as_revenue_percentages' => false, 'allocations' => [$a => 30, $b => 30]]),
        ]))->assertSessionHasErrors(['rows.0.allocations']);
    }

    // ── Flow and copying ────────────────────────────────────────────────────

    public function test_save_and_next_from_expenses_leads_to_the_assets(): void
    {
        $project = $this->study();

        $this->actingAs($this->owner)->put(route('projects.expenses.update', $project), ['rows' => [], 'after' => 'next'])
            ->assertRedirect(route('projects.assets.edit', $project));
    }

    public function test_copying_a_study_copies_its_assets_with_the_product_shares_following(): void
    {
        $project = $this->study();
        [$a, $b] = $project->products()->orderBy('id')->pluck('id')->all();

        $this->actingAs($this->owner)->put(route('projects.assets.update', $project), $this->body([
            $this->asset(['name' => 'Press copy', 'is_as_revenue_percentages' => false, 'allocations' => [$a => 70, $b => 30]]),
        ]))->assertSessionHasNoErrors();

        $copy = app(ProjectService::class)->copy($project, 'Plant A copy', $this->owner);
        $copy->load('products', 'fixedAssets');
        $copiedAsset = $copy->fixedAssets->first();
        $newIds = $copy->products->pluck('id')->all();

        $this->assertNotNull($copiedAsset);
        $this->assertEqualsCanonicalizing($newIds, array_map('intval', array_keys((array) $copiedAsset->product_allocations)));
        $this->assertEmpty(array_intersect($newIds, [$a, $b]));
    }
}
