<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsSalesStudy;
use Tests\TestCase;

// Product sales rebuild, Stage C: what the product form is given (channels, markets, exchange rates, saved shares, export pattern)
// and the results by channel and market.   Run:  php artisan test --filter=ProductScreenSegmentsTest
class ProductScreenSegmentsTest extends TestCase
{
    use BuildsSalesStudy;
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
    }

    /** The props of the product form as plain arrays. @return array<string,mixed> */
    private function formProps(Project $project, ?User $by = null): array
    {
        $response = $this->actingAs($by ?? $this->owner)->get(route('projects.products.edit', [$project, $this->valve($project)]))->assertOk();

        return $this->props($response);
    }

    private function props(TestResponse $response): array
    {
        $page = $response->viewData('page');

        return json_decode(is_string($page) ? $page : json_encode($page), true)['props'];
    }

    public function test_a_new_product_is_given_every_channel_and_market_of_the_study(): void
    {
        $project = $this->study();
        $props = $this->formProps($project);

        $this->assertTrue($props['salesSettings']['enabled']);
        $this->assertTrue($props['salesSettings']['hasExport']);
        $this->assertSame('egp', $props['salesSettings']['currency']);
        $this->assertSame(['Retail', 'Wholesale', 'Distributors'], array_column($props['segments']['local'], 'name'));
        $this->assertSame(['Saudi Arabia', 'UAE', 'Italy'], array_column($props['segments']['export'], 'name'));
        $this->assertSame(['usd', 'usd', 'euro'], array_column($props['segments']['export'], 'currency'));
        $this->assertSame(['Retail', 'Wholesale', 'Distributors'], array_column($props['salesSettings']['channels'], 'name'));
    }

    public function test_a_new_product_starts_with_the_whole_quantity_on_the_first_channel_and_market(): void
    {
        $project = $this->study();
        $props = $this->formProps($project);

        $this->assertEqualsWithDelta(100.0, (float) $props['segments']['local'][0]['allocation_percentages'][0], 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $props['segments']['local'][1]['allocation_percentages'][0], 0.001);
        $this->assertEqualsWithDelta(100.0, (float) $props['segments']['export'][0]['allocation_percentages'][4], 0.001);
        $this->assertFalse($props['segments']['local'][0]['uses_own_terms']);
        $this->assertCount(2, $props['segments']['local'][0]['collection_policy_value']);   // the product's terms, first year and later years
    }

    public function test_the_exchange_rates_of_the_study_are_given_year_by_year(): void
    {
        $project = $this->study();
        $fx = $this->formProps($project)['salesSettings']['fx'];

        $this->assertEqualsWithDelta(50.0, (float) $fx['usd'][0], 0.001);
        $this->assertEqualsWithDelta(62.0, (float) $fx['usd'][4], 0.001);
        $this->assertEqualsWithDelta(55.0, (float) $fx['euro'][0], 0.001);
    }

    public function test_saved_shares_prices_and_own_terms_come_back(): void
    {
        $project = $this->study();
        $own = array_fill(0, 2, ['cash_payment' => 100, 'rate' => [0, 0], 'due_in_days' => [30, 60]]);
        $this->saved($project, ['segments' => [
            'local' => $this->channelRows($project, ['Retail' => ['uses_own_terms' => true, 'collection_policy_value' => $own]]),
            'export' => $this->marketRows($project),
        ]]);

        $props = $this->formProps($project);
        $retail = $props['segments']['local'][0];

        $this->assertEqualsWithDelta(50.0, (float) $retail['allocation_percentages'][2], 0.001);
        $this->assertEqualsWithDelta(10.0, (float) $retail['price_differences'][2], 0.001);
        $this->assertTrue($retail['uses_own_terms']);
        $this->assertEqualsWithDelta(100.0, (float) $retail['collection_policy_value'][0]['cash_payment'], 0.001);
        $this->assertFalse($props['segments']['local'][1]['uses_own_terms']);
        $this->assertEqualsWithDelta(190.0, (float) $props['segments']['export'][2]['first_foreign_price'], 0.001);
        $this->assertEqualsWithDelta(-5.0, (float) $props['segments']['local'][2]['price_differences'][4], 0.001);
    }

    public function test_the_export_pattern_comes_back_and_starts_as_the_local_one(): void
    {
        $project = $this->study();
        $this->assertSame('flat', $this->formProps($project)['exportSeasonality']['type']);

        $this->saved($project, ['seasonality' => ['type' => 'quarterly', 'quarterly' => [20, 30, 35, 15]], 'export_seasonality' => ['type' => 'monthly', 'monthly' => [10, 10, 10, 10, 10, 10, 10, 10, 5, 5, 5, 5]]]);
        $props = $this->formProps($project);

        $this->assertSame('quarterly', $props['seasonality']['type']);
        $this->assertSame('monthly', $props['exportSeasonality']['type']);
        $this->assertEqualsWithDelta(5.0, (float) $props['exportSeasonality']['monthly'][11], 0.001);
    }

    public function test_the_results_list_every_channel_and_market_by_year(): void
    {
        $project = $this->study();
        $this->saved($project);

        $first = $this->formProps($project)['results'][0];
        $this->assertSame(2026, $first['year']);
        $this->assertEqualsWithDelta(129240000, (float) $first['sales_value'], 1);

        $local = collect($first['local_segments'])->keyBy('name');
        $export = collect($first['export_segments'])->keyBy('name');
        $this->assertEqualsWithDelta(33000000, (float) $local['Retail']['value'], 1);
        $this->assertEqualsWithDelta(3000, (float) $local['Retail']['quantity'], 0.001);
        $this->assertEqualsWithDelta(11000, (float) $local['Retail']['price'], 0.001);
        $this->assertEqualsWithDelta(12540000, (float) $export['Italy']['value'], 1);
        $this->assertCount(3, $first['local_segments']);
        $this->assertCount(3, $first['export_segments']);
    }

    public function test_with_export_off_the_form_has_no_markets_and_no_export_pattern(): void
    {
        $project = $this->study(['has_export_sales' => false, 'export_markets' => [], 'fx_rates' => []]);
        $props = $this->formProps($project);

        $this->assertTrue($props['salesSettings']['enabled']);
        $this->assertFalse($props['salesSettings']['hasExport']);
        $this->assertSame([], $props['segments']['export']);
        $this->assertNull($props['exportSeasonality']);
        $this->assertCount(3, $props['segments']['local']);
    }

    public function test_a_study_with_no_channels_or_markets_gets_the_old_form(): void
    {
        $project = $this->study(null);
        $props = $this->formProps($project);

        $this->assertFalse($props['salesSettings']['enabled']);
        $this->assertNull($props['segments']);
        $this->assertNull($props['exportSeasonality']);
    }

    public function test_a_person_who_may_only_look_gets_the_form_read_only(): void
    {
        $project = $this->study();
        $viewer = User::factory()->forCompany(Company::find($this->owner->company_id))->create();
        $this->actingAs($this->owner)->put(route('projects.shares', $project), ['shares' => [['user_id' => $viewer->id, 'can_edit' => false]]])->assertSessionHasNoErrors();

        $props = $this->formProps($project, $viewer);

        $this->assertTrue($props['readOnly']);
        $this->assertCount(3, $props['segments']['local']);
    }
}
