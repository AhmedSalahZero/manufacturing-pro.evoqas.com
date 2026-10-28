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
