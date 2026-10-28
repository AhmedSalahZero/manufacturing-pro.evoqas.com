<?php

namespace Tests\Feature;

use App\Models\FixedAssetOpeningBalance;
use App\Models\OtherDebtorsOpeningBalance;
use App\Models\SupplierPayableOpeningBalance;
use App\Models\EquityOpeningBalance;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

// The opening balances form: saving, checks, access and the numbers that come out.
// Run:  php artisan test --filter=OpeningBalancesTest
class OpeningBalancesTest extends TestCase
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

    /** Figures that balance: assets 150 000 = liabilities 20 000 + loan 30 000 + paid-up capital 100 000. */
    private function balanced(array $over = []): array
    {
        return array_merge([
            'fixed_assets' => [['name' => 'Press', 'gross_amount' => 120000, 'accumulated_depreciation' => 30000, 'monthly_depreciation' => 1000, 'monthly_counts' => 24, 'admin_depreciation_percentage' => 20, 'is_as_revenue_percentages' => true]],
            'cash' => ['cash_and_bank_amount' => 10000, 'customer_receivable_amount' => 50000, 'settlements' => [1 => 30000, 2 => 20000]],
            'other_debtors' => [],
            'suppliers' => ['amount' => 20000, 'settlements' => [3 => 20000]],
            'other_creditors' => [],
            'taxes' => ['vat_amount' => 0, 'credit_withhold_taxes' => 0, 'corporate_taxes_payable' => 0],
            'loans' => [['amount' => 30000, 'installments' => [11 => 30000], 'interests' => [11 => 900]]],
            'other_long_term' => [],
            'equity' => ['paid_up_capital_amount' => 100000, 'legal_reserve' => 0, 'retained_earnings' => 0],
            'after' => 'stay',
        ], $over);
    }

    private function saveForm(Project $project, array $body)
    {
        return $this->actingAs($this->owner)->put(route('projects.opening.update', $project), $body);
    }

    // ── Access ──────────────────────────────────────────────────────────────

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $project = $this->study();
        $this->app['auth']->forgetGuards();

        $this->get(route('projects.opening.edit', $project))->assertRedirect('/login');
    }

    public function test_a_stranger_cannot_look_or_save(): void
    {
        $project = $this->study();
        $stranger = User::factory()->companyAdmin()->create();

        $this->actingAs($stranger)->get(route('projects.opening.edit', $project))->assertForbidden();
        $this->actingAs($stranger)->put(route('projects.opening.update', $project), $this->balanced())->assertForbidden();
    }

    public function test_a_new_company_has_no_opening_balances(): void
    {
        $project = $this->study();
        $project->update(['new_company' => true]);

        $this->actingAs($this->owner)->get(route('projects.opening.edit', $project))->assertNotFound();
        $this->saveForm($project, $this->balanced())->assertNotFound();
    }

    public function test_the_form_opens_with_the_study_months_and_empty_lists(): void
    {
        $project = $this->study();

        $this->actingAs($this->owner)->get(route('projects.opening.edit', $project))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('OpeningBalances/Edit')
                ->has('months', 36)
                ->has('products', 2)
                ->has('data.fixed_assets', 0)
                ->has('data.loans', 0)
                ->where('data.cash.customer_receivable_amount', fn ($v) => (float) $v === 0.0)
                ->where('readOnly', false));
    }

    // ── Saving ──────────────────────────────────────────────────────────────

    public function test_balanced_figures_are_saved_and_their_statements_calculated(): void
    {
        $project = $this->study();

        $this->saveForm($project, $this->balanced())->assertSessionHasNoErrors()->assertSessionHas('success')->assertRedirect(route('projects.opening.edit', $project));

        $cash = $project->cashAndBankOpeningBalances()->firstOrFail();
        $end = $cash->statement['monthly']['end_balance'];
        $this->assertEqualsWithDelta(50000, $end[0], 0.01);
        $this->assertEqualsWithDelta(20000, $end[1], 0.01);
        $this->assertEqualsWithDelta(0, $end[2], 0.01);

        $loan = $project->longTermLoanOpeningBalances()->firstOrFail();
        $this->assertEqualsWithDelta(30000, $loan->statement['monthly']['end_balance'][10], 0.01);
        $this->assertEqualsWithDelta(0, $loan->statement['monthly']['end_balance'][11], 0.01);
        $this->assertEqualsWithDelta(900, (float) $loan->interests[11], 0.01);

        $supplier = SupplierPayableOpeningBalance::where('project_id', $project->id)->firstOrFail();
        $this->assertEqualsWithDelta(0, $supplier->statement['monthly']['end_balance'][3], 0.01);
        // The quarterly view books the April payment at the end of the quarter (June = month 5); until then the balance stays.
        $this->assertEqualsWithDelta(20000, $supplier->statement['quarterly']['end_balance'][4], 0.01);
        $this->assertEqualsWithDelta(0, $supplier->statement['quarterly']['end_balance'][5], 0.01);
    }

    public function test_an_owned_asset_depreciates_into_the_products(): void
    {
        $project = $this->study();

        $this->saveForm($project, $this->balanced())->assertSessionHasNoErrors();

        $asset = FixedAssetOpeningBalance::where('project_id', $project->id)->firstOrFail();
        $this->assertEqualsWithDelta(200, (float) $asset->admin_depreciations[0], 0.01);
        $this->assertEqualsWithDelta(800, (float) $asset->manufacturing_depreciations[0], 0.01);
        $this->assertEqualsWithDelta(89000, $asset->statement['monthly']['end_balance'][0], 0.01);   // 90 000 net before, 1 000 depreciated

        $this->assertSame(2, DB::table('product_expense_allocations')->where('project_id', $project->id)->where('is_opening_depreciation', 1)->count());
        $valve = $project->products()->where('name', 'Valve')->firstOrFail();
        $shared = (array) json_decode((string) DB::table('products')->where('id', $valve->id)->value('product_depreciation_allocation'), true);
        $this->assertEqualsWithDelta(800, (float) ($shared[0] ?? 0), 0.01);   // the manufacturing part of the first month (Valve is the product that sells)
    }

    public function test_equity_is_repeated_for_every_operation_month(): void
    {
        $project = $this->study();

        $this->saveForm($project, $this->balanced())->assertSessionHasNoErrors();

        $equity = EquityOpeningBalance::where('project_id', $project->id)->firstOrFail();
        $this->assertEqualsWithDelta(100000, (float) $equity->paid_up_capital_extended[0], 0.01);
        $this->assertEqualsWithDelta(100000, (float) $equity->paid_up_capital_extended[35], 0.01);
    }

    public function test_unbalanced_figures_are_not_saved_and_the_difference_is_reported(): void
    {
        $project = $this->study();

        $this->saveForm($project, $this->balanced(['equity' => ['paid_up_capital_amount' => 90000, 'legal_reserve' => 0, 'retained_earnings' => 0], 'after' => 'list']))
            ->assertSessionHasErrors('balance');

        // The save was undone: nothing of the form is kept.
        $this->assertSame(0, EquityOpeningBalance::where('project_id', $project->id)->count());
        $this->assertSame(0, $project->cashAndBankOpeningBalances()->count());
    }

    public function test_corporate_tax_payable_counts_as_a_liability(): void
    {
        $project = $this->study();

        $this->saveForm($project, $this->balanced(['taxes' => ['vat_amount' => 0, 'credit_withhold_taxes' => 0, 'corporate_taxes_payable' => 5000]]))
            ->assertSessionHasErrors('balance');
    }

    public function test_save_and_close_goes_to_the_list_when_balanced(): void
    {
        $project = $this->study();

        $this->saveForm($project, $this->balanced(['after' => 'list']))->assertRedirect(route('projects.index'));
    }

    public function test_lines_not_sent_again_are_deleted_and_single_sections_keep_one_row(): void
    {
        $project = $this->study();
        $this->saveForm($project, $this->balanced(['other_debtors' => [['name' => 'Staff loans', 'amount' => 0, 'settlements' => []]]]))->assertSessionHasNoErrors();
        $this->assertSame(1, OtherDebtorsOpeningBalance::where('project_id', $project->id)->count());

        $this->saveForm($project, $this->balanced(['other_debtors' => [], 'cash' => ['cash_and_bank_amount' => 10001, 'customer_receivable_amount' => 50000, 'settlements' => []], 'equity' => ['paid_up_capital_amount' => 100001, 'legal_reserve' => 0, 'retained_earnings' => 0]]))->assertSessionHasNoErrors();

        $this->assertSame(0, OtherDebtorsOpeningBalance::where('project_id', $project->id)->count());
        $this->assertSame(1, $project->cashAndBankOpeningBalances()->count());
    }

    // ── Checks ──────────────────────────────────────────────────────────────

    public function test_months_cannot_add_up_to_more_than_the_balance(): void
    {
        $project = $this->study();

        $this->saveForm($project, $this->balanced(['other_debtors' => [['name' => 'Staff', 'amount' => 1000, 'settlements' => [1 => 800, 2 => 800]]]]))
            ->assertSessionHasErrors(['other_debtors.0.settlements']);
    }

    public function test_months_must_be_inside_the_study(): void
    {
        $project = $this->study();

        $this->saveForm($project, $this->balanced(['suppliers' => ['amount' => 20000, 'settlements' => [99 => 5000]]]))
            ->assertSessionHasErrors(['suppliers.settlements']);
    }

    public function test_accumulated_depreciation_cannot_exceed_the_gross_amount(): void
    {
        $project = $this->study();
        $body = $this->balanced();
        $body['fixed_assets'][0]['accumulated_depreciation'] = 130000;

        $this->saveForm($project, $body)->assertSessionHasErrors(['fixed_assets.0.accumulated_depreciation']);
    }

    public function test_typed_product_shares_must_total_100(): void
    {
        $project = $this->study();
        [$a, $b] = $project->products()->orderBy('id')->pluck('id')->all();
        $body = $this->balanced();
        $body['fixed_assets'][0]['is_as_revenue_percentages'] = false;
        $body['fixed_assets'][0]['allocations'] = [$a => 30, $b => 30];

        $this->saveForm($project, $body)->assertSessionHasErrors(['fixed_assets.0.allocations']);
    }

    public function test_a_debtor_needs_a_name(): void
    {
        $project = $this->study();

        $this->saveForm($project, $this->balanced(['other_debtors' => [['name' => '', 'amount' => 10, 'settlements' => []]]]))
            ->assertSessionHasErrors(['other_debtors.0.name']);
    }

    // ── Flow and copying ────────────────────────────────────────────────────

    public function test_save_and_next_from_the_fixed_assets_leads_to_the_opening_balances(): void
    {
        $project = $this->study();

        $this->actingAs($this->owner)->put(route('projects.assets.update', $project), ['rows' => [], 'after' => 'next'])
            ->assertRedirect(route('projects.opening.edit', $project));
    }

    public function test_copying_a_study_copies_its_opening_balances_with_the_product_shares_following(): void
    {
        $project = $this->study();
        [$a, $b] = $project->products()->orderBy('id')->pluck('id')->all();
        $body = $this->balanced();
        $body['fixed_assets'][0]['is_as_revenue_percentages'] = false;
        $body['fixed_assets'][0]['allocations'] = [$a => 70, $b => 30];
        $this->saveForm($project, $body)->assertSessionHasNoErrors();

        $copy = app(ProjectService::class)->copy($project, 'Plant A copy', $this->owner);
        $copy->load('products');
        $asset = FixedAssetOpeningBalance::where('project_id', $copy->id)->firstOrFail();

        $this->assertEqualsCanonicalizing($copy->products->pluck('id')->all(), array_map('intval', array_keys((array) $asset->product_allocations)));
        $this->assertSame(1, $copy->cashAndBankOpeningBalances()->count());
        $this->assertSame(1, $copy->longTermLoanOpeningBalances()->count());
    }
}
