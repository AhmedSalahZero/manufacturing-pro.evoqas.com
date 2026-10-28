<?php

namespace Tests\Feature;

use App\Models\BalanceSheet;
use App\Models\CashInOutStatement;
use App\Models\Company;
use App\Models\IncomeStatement;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

// The financial statements screen and the calculation behind it: income statement, cash flow, balance sheet, VAT and tax.
// Run:  php artisan test --filter=StatementsTest
class StatementsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
    }

    /** A 3-year study from 2026-01 (months 0..35). Valve sells 100 pieces a month (capacity 1200 a year) at 10, so 1 000 a month (VAT 14 %, 1 % withheld by the customer); Pump has no sales plan. */
    private function study(bool $newCompany = true): Project
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

    /** Staff, expenses and a part-loan machine, so every statement has something in it. */
    private function fillCosts(Project $project): void
    {
        $this->actingAs($this->owner)->put(route('projects.manpower.update', $project), [
            'salaries_annual_increase' => 10, 'salaries_tax_rate' => 5, 'social_insurance_rate' => 5, 'after' => 'stay',
            'rows' => [['type' => 'direct_labor', 'position' => 'Welder', 'avg_salary' => 1000, 'existing_count' => 2, 'hirings' => [0 => 1]]],
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->owner)->put(route('projects.expenses.update', $project), ['after' => 'stay', 'rows' => [
            ['relation_name' => 'fixed_monthly_repeating_amount', 'category_id' => 'general-expenses', 'name' => 'Office', 'amount' => 500,
                'start' => '2026-01', 'end' => '2028-12', 'increase_rate' => 0, 'payment_terms' => 'cash', 'is_as_revenue_percentages' => true],
        ]])->assertSessionHasNoErrors();

        $this->actingAs($this->owner)->put(route('projects.assets.update', $project), ['after' => 'stay', 'rows' => [[
            'name' => 'Press', 'counts' => 2, 'amount' => 6000, 'depreciation_duration' => 5, 'start' => '2026-01', 'end' => '2026-03',
            'admin_depreciation_percentage' => 30, 'replacement_cost_rate' => 0, 'replacement_cost_interval' => 1,
            'payment_terms' => 'cash', 'equity_funding_rate' => 50, 'interest_rate' => 12, 'grace_period' => 3, 'tenor' => 24, 'installment_interval' => 'quarterly',
            'is_as_revenue_percentages' => true,
        ]]])->assertSessionHasNoErrors();
    }

    /** Figures that balance: assets 150 000 = liabilities 20 000 + loan 30 000 + paid-up capital 100 000. */
    private function openingBalances(): array
    {
        return [
            'fixed_assets' => [['name' => 'Old press', 'gross_amount' => 120000, 'accumulated_depreciation' => 30000, 'monthly_depreciation' => 1000, 'monthly_counts' => 24, 'admin_depreciation_percentage' => 20, 'is_as_revenue_percentages' => true]],
            'cash' => ['cash_and_bank_amount' => 10000, 'customer_receivable_amount' => 50000, 'settlements' => [1 => 30000, 2 => 20000]],
            'other_debtors' => [],
            'suppliers' => ['amount' => 20000, 'settlements' => [3 => 20000]],
            'other_creditors' => [],
            'taxes' => ['vat_amount' => 0, 'credit_withhold_taxes' => 0, 'corporate_taxes_payable' => 0],
            'loans' => [['amount' => 30000, 'installments' => [11 => 30000], 'interests' => [11 => 900]]],
            'other_long_term' => [],
            'equity' => ['paid_up_capital_amount' => 100000, 'legal_reserve' => 0, 'retained_earnings' => 0],
            'after' => 'stay',
        ];
    }

    /** @return array<string,mixed> one row of a tab from the page props */
    private function row(Project $project, string $tab, string $key, ?User $as = null): array
    {
        $page = $this->actingAs($as ?? $this->owner)->get(route('projects.statements', $project))->assertOk()->viewData('page');
        $props = json_decode(is_string($page) ? $page : json_encode($page), true)['props'];   // plain arrays, whatever form the page comes in

        foreach ($props['statements']['tabs'][$tab] as $row) {
            if ($row['key'] === $key) {
                return $row;
            }
        }

        $this->fail("No row $key in the $tab tab");
    }

    // ── Access ──────────────────────────────────────────────────────────────

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $project = $this->study();
        $this->app['auth']->forgetGuards();

        $this->get(route('projects.statements', $project))->assertRedirect('/login');
    }

    public function test_a_stranger_cannot_look(): void
    {
        $project = $this->study();
        $stranger = User::factory()->companyAdmin()->create();

        $this->actingAs($stranger)->get(route('projects.statements', $project))->assertForbidden();
    }

    public function test_a_colleague_with_view_access_can_look(): void
    {
        $company = Company::factory()->create();
        $owner = User::factory()->forCompany($company)->create();
        $viewer = User::factory()->forCompany($company)->create();
        $this->owner = $owner;
        $project = $this->study();

        $this->actingAs($owner)->put(route('projects.shares', $project), ['shares' => [['user_id' => $viewer->id, 'can_edit' => false]]])->assertSessionHasNoErrors();

        $this->actingAs($viewer)->get(route('projects.statements', $project))->assertOk()->assertInertia(fn (Assert $page) => $page->where('ready', true));
    }

    // ── The screen ──────────────────────────────────────────────────────────

    public function test_a_study_without_dates_or_products_has_nothing_to_show_yet(): void
    {
        $project = app(ProjectService::class)->create($this->owner, 'Empty');

        $this->actingAs($this->owner)->get(route('projects.statements', $project))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Statements/Show')->where('ready', false)->missing('statements'));
    }

    public function test_the_page_has_three_tabs_and_a_column_per_study_year(): void
    {
        $project = $this->study();

        $this->actingAs($this->owner)->get(route('projects.statements', $project))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Statements/Show')
                ->where('ready', true)
                ->where('taxRate', fn ($v) => (float) $v === 22.5)
                ->has('statements.years', 3)
                ->where('statements.years.0.year', 2026)
                ->has('statements.years.0.months', 12)
                ->has('statements.tabs.income')
                ->has('statements.tabs.cash')
                ->has('statements.tabs.balance'));
    }

    public function test_the_revenue_is_what_the_product_sells(): void
    {
        $project = $this->study();

        $revenue = $this->row($project, 'income', 'revenue');
        $this->assertEqualsWithDelta(12000.0, (float) $revenue['yearly'][0], 0.01);   // capacity 1200 a year = 100 pieces a month x 10 x 12 months
        $this->assertEqualsWithDelta(1000.0, (float) $revenue['monthly'][5], 0.01);
    }

    // ── The numbers ─────────────────────────────────────────────────────────

    public function test_the_balance_sheet_balances_for_a_new_company_with_staff_expenses_and_a_loan(): void
    {
        $project = $this->study();
        $this->fillCosts($project);

        $this->actingAs($this->owner)->get(route('projects.statements', $project))
            ->assertInertia(fn (Assert $page) => $page->where('statements.check.balanced', true));

        foreach ($this->row($project, 'balance', 'check')['monthly'] as $month => $gap) {
            $this->assertEqualsWithDelta(0.0, (float) $gap, 0.5, "balance sheet check, month $month");
        }
    }

    public function test_the_balance_sheet_balances_for_an_existing_company_with_opening_balances(): void
    {
        $project = $this->study(false);
        $this->actingAs($this->owner)->put(route('projects.opening.update', $project), $this->openingBalances())->assertSessionHasNoErrors();
        $this->fillCosts($project);

        $this->actingAs($this->owner)->get(route('projects.statements', $project))
            ->assertInertia(fn (Assert $page) => $page->where('statements.check.balanced', true));

        // On the first day the company owns the 10 000 cash and the 50 000 customers owe: they are in the first month's figures.
        $this->assertGreaterThan(0.0, (float) $this->row($project, 'balance', 'customer_receivables')['monthly'][0]);
        $this->assertGreaterThan(0.0, (float) $this->row($project, 'balance', 'net_fixed_assets')['monthly'][0]);
    }

    public function test_the_cost_of_goods_sold_is_the_labour_when_nothing_is_kept_in_stock(): void
    {
        $project = $this->study();
        $this->fillCosts($project);

        $valve = $project->products()->where('name', 'Valve')->firstOrFail();
        foreach ([0, 5] as $month) {
            $this->assertEqualsWithDelta((float) $valve->product_manpower_allocation[$month], (float) $valve->product_manpower_statement['cogs'][$month], 0.01, "month $month");
        }
        $this->assertGreaterThan(0.0, (float) $valve->product_inventory_value_statement['cogs'][0]);
    }

    public function test_vat_is_paid_the_month_after_the_sales(): void
    {
        $project = $this->study();
        $vat = $project->refresh()->vat_statements['monthly'];

        $this->assertEqualsWithDelta(140.0, (float) $vat['addition'][0], 0.01, '14 % of 1 000');
        $this->assertEqualsWithDelta(0.0, (float) $vat['payment'][0], 0.01);
        $this->assertEqualsWithDelta(140.0, (float) $vat['payment'][1], 0.01);
    }

    public function test_corporate_tax_is_booked_in_december_and_paid_in_april_after_the_withheld_tax(): void
    {
        $project = $this->study();
        $tax = $this->row($project, 'income', 'tax');

        $this->assertEqualsWithDelta(0.0, (float) $tax['monthly'][0], 0.01);
        $this->assertGreaterThan(0.0, (float) $tax['monthly'][11], 'the tax of 2026 is booked in December 2026');

        $statement = $project->refresh()->corporate_taxes_statement['monthly'];
        $this->assertGreaterThan(0.0, (float) $statement['addition'][0], 'customers withheld 1 % of the sales');
        $this->assertLessThan((float) $tax['monthly'][11], (float) $statement['total_due'][11], 'the withheld tax is deducted');
        $this->assertEqualsWithDelta((float) $statement['total_due'][11], (float) $statement['payment'][15], 0.01, 'the rest is paid in April 2027');
        $this->assertEqualsWithDelta(0.0, (float) $statement['payment'][14], 0.01);
    }

    public function test_the_statements_are_saved_for_the_later_screens(): void
    {
        $project = $this->study();
        $this->fillCosts($project);

        $income = IncomeStatement::where('project_id', $project->id)->firstOrFail();
        $this->assertEqualsWithDelta(1000.0, (float) $income->total_sales_revenues[5], 0.01);
        $this->assertCount(3, $income->annually_net_profit);

        $cash = CashInOutStatement::where('project_id', $project->id)->firstOrFail();
        $this->assertArrayHasKey(35, $cash->cash_end_balance);
        $this->assertGreaterThanOrEqual(-0.01, min(array_map('floatval', $cash->cash_end_balance)), 'cash is never below zero');

        $sheet = BalanceSheet::where('project_id', $project->id)->firstOrFail();
        $this->assertCount(3, $sheet->total_current_assets);
        $this->assertCount(3, $sheet->equity_funding_percentages);
    }

    public function test_saving_again_replaces_the_saved_statements_instead_of_adding_rows(): void
    {
        $project = $this->study();
        $this->fillCosts($project);

        $this->actingAs($this->owner)->put(route('projects.expenses.update', $project), ['after' => 'stay', 'rows' => [
            ['relation_name' => 'fixed_monthly_repeating_amount', 'category_id' => 'general-expenses', 'name' => 'Office', 'amount' => 700,
                'start' => '2026-01', 'end' => '2028-12', 'increase_rate' => 0, 'payment_terms' => 'cash', 'is_as_revenue_percentages' => true],
        ]])->assertSessionHasNoErrors();

        $this->assertSame(1, IncomeStatement::where('project_id', $project->id)->count());
        $this->assertSame(1, BalanceSheet::where('project_id', $project->id)->count());
        $this->assertSame(1, CashInOutStatement::where('project_id', $project->id)->count());
    }
}
