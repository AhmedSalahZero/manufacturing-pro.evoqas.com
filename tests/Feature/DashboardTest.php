<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\IncomeStatement;
use App\Models\User;
use App\Services\ProjectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsStudy;
use Tests\TestCase;

// The dashboard screen, its notes and the printable report.
// Run:  php artisan test --filter=DashboardTest
class DashboardTest extends TestCase
{
    use BuildsStudy;
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
    }

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $project = $this->study();
        $this->asGuest();

        $this->get(route('projects.dashboard', $project))->assertRedirect('/login');
        $this->get(route('projects.report', $project))->assertRedirect('/login');
    }

    public function test_a_stranger_cannot_look(): void
    {
        $project = $this->study();
        $stranger = User::factory()->companyAdmin()->create();

        $this->actingAs($stranger)->get(route('projects.dashboard', $project))->assertForbidden();
        $this->actingAs($stranger)->get(route('projects.report', $project))->assertForbidden();
    }

    public function test_a_study_without_dates_or_products_has_nothing_to_show_yet(): void
    {
        $project = app(ProjectService::class)->create($this->owner, 'Empty');

        $this->actingAs($this->owner)->get(route('projects.dashboard', $project))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Dashboard/Show')->where('ready', false)->missing('dashboard'));

        $this->actingAs($this->owner)->get(route('projects.report', $project))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Report/Show')->where('ready', false));
    }

    public function test_the_dashboard_has_a_value_for_every_year(): void
    {
        $project = $this->study();
        $props = $this->props($this->actingAs($this->owner)->get(route('projects.dashboard', $project))->assertOk());
        $d = $props['dashboard'];

        $this->assertTrue($props['ready']);
        $this->assertSame([2026, 2027, 2028], array_column($d['years'], 'year'));
        foreach (['value', 'growth', 'accumulated'] as $key) {
            $this->assertCount(3, $d['revenue'][$key], "revenue $key");
        }
        $this->assertCount(3, $d['dcf']['free_cash_flow']);
        $this->assertCount(3, $d['ratios']['current_ratio']);
        $this->assertArrayHasKey('irr', $d['kpi']);
        $this->assertArrayHasKey('npv', $d['kpi']);
        $this->assertArrayHasKey('payback', $d['kpi']);
    }

    public function test_the_revenue_charts_follow_the_income_statement(): void
    {
        $project = $this->study();
        $d = $this->props($this->actingAs($this->owner)->get(route('projects.dashboard', $project)))['dashboard'];

        $this->assertEqualsWithDelta(12000.0, $d['revenue']['value'][0], 0.01);
        $this->assertEqualsWithDelta(0.0, $d['revenue']['growth'][0], 0.01);
        $this->assertEqualsWithDelta(0.0, $d['revenue']['growth'][1], 0.01, 'flat sales: no growth');
        $this->assertEqualsWithDelta(36000.0, $d['revenue']['accumulated'][2], 0.01);

        $saved = IncomeStatement::where('project_id', $project->id)->first();
        $this->assertEqualsWithDelta(36000.0, array_sum((array) $saved->total_sales_revenues), 0.01);
    }

    public function test_required_investment_is_what_the_fixed_assets_and_funding_say(): void
    {
        $project = $this->study();
        $d = $this->props($this->actingAs($this->owner)->get(route('projects.dashboard', $project)))['dashboard'];

        $this->assertEqualsWithDelta(12000.0, $d['investment']['fixed_assets'], 0.01, '2 presses at 6 000');
        $this->assertEqualsWithDelta(6000.0, $d['investment']['loans'], 0.5, '50 % of the machines is a loan');
        $this->assertGreaterThan(5999.0, $d['investment']['equity'], 'at least the 50 % equity share of the machines');
        $this->assertEqualsWithDelta($d['investment']['fixed_assets'] + $d['investment']['working_capital'], $d['kpi']['investment'], 0.01);
    }

    public function test_free_cash_flow_follows_the_rule_of_the_old_application(): void
    {
        $project = $this->study();
        $d = $this->props($this->actingAs($this->owner)->get(route('projects.dashboard', $project)))['dashboard']['dcf'];

        foreach ([0, 1, 2] as $i) {
            $expected = $d['ebit'][$i] + $d['depreciation'][$i] + $d['working_capital'][$i] - $d['taxes'][$i] - $d['capex'][$i];
            $this->assertEqualsWithDelta($expected, $d['free_cash_flow'][$i], 0.01, "year $i");
        }
        $this->assertEqualsWithDelta(0.0, $d['terminal_value'][0], 0.01, 'the terminal value sits in the last year only');
    }

    public function test_the_dashboard_can_be_read_by_a_colleague_with_view_access(): void
    {
        $company = Company::factory()->create();
        $this->owner = User::factory()->forCompany($company)->create();
        $viewer = User::factory()->forCompany($company)->create();
        $project = $this->study();
        $this->actingAs($this->owner)->put(route('projects.shares', $project), ['shares' => [['user_id' => $viewer->id, 'can_edit' => false]]])->assertSessionHasNoErrors();

        $this->actingAs($viewer)->get(route('projects.dashboard', $project))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('ready', true)->where('canEdit', false));
        $this->actingAs($viewer)->put(route('projects.dashboard.comments', $project), ['one' => 'x', 'two' => 'y'])->assertForbidden();
    }

    public function test_the_notes_are_saved_and_shown_again(): void
    {
        $project = $this->study();

        $this->actingAs($this->owner)->put(route('projects.dashboard.comments', $project), ['one' => 'Sales are steady.', 'two' => 'Cash is tight in year 1.'])
            ->assertSessionHasNoErrors();

        $this->assertSame('Sales are steady.', $project->refresh()->dashboard_comment_1);
        $this->actingAs($this->owner)->get(route('projects.dashboard', $project))
            ->assertInertia(fn (Assert $page) => $page->where('comments.one', 'Sales are steady.')->where('comments.two', 'Cash is tight in year 1.')->where('canEdit', true));
    }

    public function test_a_note_that_is_too_long_is_refused(): void
    {
        $project = $this->study();

        $this->actingAs($this->owner)->put(route('projects.dashboard.comments', $project), ['one' => str_repeat('a', 5001), 'two' => ''])
            ->assertSessionHasErrors('one');
    }

    public function test_the_report_has_the_summary_the_dashboard_and_the_statements(): void
    {
        $project = $this->study();
        $props = $this->props($this->actingAs($this->owner)->get(route('projects.report', $project))->assertOk());

        $this->assertTrue($props['ready']);
        $this->assertSame('Plant A', $props['project']['name']);
        $this->assertSame('A small valve plant.', $props['project']['intro']);
        $this->assertEqualsWithDelta(22.5, $props['project']['tax'], 0.001);
        $this->assertCount(3, $props['statements']['years']);
        $this->assertNotEmpty($props['statements']['tabs']['balance']);
        $this->assertCount(3, $props['dashboard']['years']);
    }
}
