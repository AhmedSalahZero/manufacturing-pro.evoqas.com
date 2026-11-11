<?php

namespace Tests\Feature;

use App\Models\ExportMarket;
use App\Models\Product;
use App\Models\ProductSegment;
use App\Models\Project;
use App\Models\SalesChannel;
use App\Models\Sensitivity;
use App\Models\User;
use App\Services\ProjectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Product sales rebuild, Stage B: saving a product's channels and markets, the validation rules, the calculation that follows,
// collections per channel and market, the two seasonality patterns and the FX what-if.
// Run:  php artisan test --filter=ProductSegmentsTest
class ProductSegmentsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
    }

    private function each(float|int $value): array
    {
        return array_fill_keys(range(0, 4), $value);
    }

    /** The study form: 5 years from 2026-01, one product (Valve, VAT 14 %, withholding 1 %) and one raw material. */
    private function form(array $sales = []): array
    {
        return [
            'name' => 'Plant A', 'new_company' => true, 'main_functional_currency' => 'egp', 'intro_paragraph' => 'A plant.', 'business_sector_id' => null,
            'start_date' => '2026-01', 'duration' => 5, 'operation_start_date' => '2026-01', 'tax_rate' => 22.5, 'return_rate' => 15, 'perpetual_growth_rate' => 3,
            'products' => [['name' => 'Valve', 'measurement_unit' => 'ton', 'selling_start_date' => '2026-01', 'vat_rate' => 14, 'withhold_tax_rate' => 1]],
            'rawMaterials' => [['name' => 'Brass', 'rm_inventory_coverage_days' => 0, 'measurement_unit' => 'kg', 'vat_rate' => 14, 'withhold_tax_rate' => 0]],
        ] + $sales;
    }

    private function salesSettings(array $override = []): array
    {
        return array_merge([
            'has_export_sales' => true,
            'sales_channels'   => [['name' => 'Retail'], ['name' => 'Wholesale'], ['name' => 'Distributors']],
            'fx_rates'         => [
                ['currency' => 'usd', 'rates' => [0 => 50, 1 => 54, 2 => 58, 3 => 60, 4 => 62]],
                ['currency' => 'euro', 'rates' => [0 => 55, 1 => 58, 2 => 61, 3 => 63, 4 => 65]],
            ],
            'export_markets'   => [['name' => 'Saudi Arabia', 'currency' => 'usd'], ['name' => 'UAE', 'currency' => 'usd'], ['name' => 'Italy', 'currency' => 'euro']],
        ], $override);
    }

    /** A saved study. With $sales = null it has no channels or markets (the old way of working). */
    private function study(?array $sales = []): Project
    {
        $project = app(ProjectService::class)->create($this->owner, 'Plant A');
        $this->actingAs($this->owner)->put(route('projects.update', $project), $this->form($sales === null ? [] : $this->salesSettings($sales)))
            ->assertSessionHasNoErrors()->assertSessionMissing('error')->assertRedirect();

        return $project->refresh();
    }

    private function valve(Project $project): Product
    {
        return $project->products()->where('name', 'Valve')->firstOrFail();
    }

    private function channel(Project $project, string $name): SalesChannel
    {
        return $project->salesChannels()->where('name', $name)->firstOrFail();
    }

    private function market(Project $project, string $name): ExportMarket
    {
        return $project->exportMarkets()->where('name', $name)->firstOrFail();
    }

    /** Retail 50 % (+10 %), Wholesale 30 % (0 %), Distributors 20 % (-5 %). */
    private function channelRows(Project $project, array $own = []): array
    {
        $rows = [];
        foreach (['Retail' => [50, 10], 'Wholesale' => [30, 0], 'Distributors' => [20, -5]] as $name => [$share, $difference]) {
            $rows[] = array_merge(['sales_channel_id' => $this->channel($project, $name)->id, 'allocation_percentages' => $this->each($share), 'price_differences' => $this->each($difference), 'uses_own_terms' => false], $own[$name] ?? []);
        }

        return $rows;
    }

    /** Saudi Arabia 50 % USD 230, UAE 30 % USD 220, Italy 20 % EUR 190. */
    private function marketRows(Project $project): array
    {
        $rows = [];
        foreach (['Saudi Arabia' => [50, 230], 'UAE' => [30, 220], 'Italy' => [20, 190]] as $name => [$share, $price]) {
            $rows[] = ['export_market_id' => $this->market($project, $name)->id, 'allocation_percentages' => $this->each($share), 'first_foreign_price' => $price, 'foreign_growth_rates' => $this->each(0), 'uses_own_terms' => false];
        }

        return $rows;
    }

    /** Capacity 12,000 tons a year, all of it sold, half local and half export. */
    private function payload(Project $project, array $override = []): array
    {
        $policy = [
            ['cash_payment' => 20, 'rate' => [50, 30], 'due_in_days' => [30, 60]],
            ['cash_payment' => 100, 'rate' => [0, 0], 'due_in_days' => [30, 60]],
        ];

        return array_merge([
            'max_capacity' => $this->each(12000), 'target_percentages' => $this->each(100), 'local_target_quantity_percentages' => $this->each(50),
            'local_growth_rates' => $this->each(0), 'local_price_per_unit' => $this->each(10000),
            'export_growth_rates' => $this->each(0), 'export_price_per_unit' => $this->each(0),
            'seasonality' => ['type' => 'flat'], 'fg_inventory_quantity' => 0, 'fg_inventory_value' => 0, 'fg_inventory_coverage_days' => 0,
            'collection_policy_value' => ['local' => $policy, 'export' => $policy],
            'segments' => array_key_exists('segments', $override) ? $override['segments'] : ['local' => $this->channelRows($project), 'export' => $project->exportMarkets()->where('name', 'Italy')->exists() ? $this->marketRows($project) : []],
            'rawMaterials' => [], 'after' => 'stay',
        ], $override);
    }

    private function save(Project $project, array $override = [])
    {
        return $this->actingAs($this->owner)->put(route('projects.products.update', [$project, $this->valve($project)]), $this->payload($project, $override));
    }

    private function saved(Project $project, array $override = []): void
    {
        $this->save($project, $override)->assertSessionHasNoErrors()->assertSessionMissing('error')->assertRedirect();
    }

    private function year(?array $monthly, int $year = 0): float
    {
        return array_sum(array_map('floatval', array_slice((array) $monthly, $year * 12, 12, true)));
    }

    private function segment(Project $project, string $kind, string $name): ProductSegment
    {
        $column = $kind === 'local' ? 'sales_channel_id' : 'export_market_id';
        $id = $kind === 'local' ? $this->channel($project, $name)->id : $this->market($project, $name)->id;

        return ProductSegment::where('product_id', $this->valve($project)->id)->where($column, $id)->firstOrFail();
    }

    // ── saving and the agreed numbers ───────────────────────

    public function test_the_channels_and_markets_of_a_product_are_saved(): void
    {
        $project = $this->study();
        $this->saved($project);

        $this->assertSame(3, ProductSegment::where('product_id', $this->valve($project)->id)->where('kind', 'local')->count());
        $this->assertSame(3, ProductSegment::where('product_id', $this->valve($project)->id)->where('kind', 'export')->count());

        $retail = $this->segment($project, 'local', 'Retail');
        $this->assertEqualsWithDelta(50.0, (float) $retail->allocation_percentages[0], 0.001);
        $this->assertEqualsWithDelta(10.0, (float) $retail->price_differences[0], 0.001);
        $this->assertFalse((bool) $retail->uses_own_terms);

        $italy = $this->segment($project, 'export', 'Italy');
        $this->assertEqualsWithDelta(190.0, (float) $italy->first_foreign_price, 0.001);
        $this->assertEqualsWithDelta(20.0, (float) $italy->allocation_percentages[4], 0.001);
    }

    public function test_saving_again_changes_the_rows_and_removes_the_ones_left_out(): void
    {
        $project = $this->study();
        $this->saved($project);
        $retailId = $this->segment($project, 'local', 'Retail')->id;

        $rows = $this->channelRows($project);
        $rows[0]['allocation_percentages'] = $this->each(70);
        $rows[1]['allocation_percentages'] = $this->each(30);
        array_pop($rows);   // Distributors is dropped
        $this->saved($project, ['segments' => ['local' => $rows, 'export' => $this->marketRows($project)], 'comment_for_sales' => 'second']);

        $this->assertSame(2, ProductSegment::where('product_id', $this->valve($project)->id)->where('kind', 'local')->count());
        $this->assertEqualsWithDelta(70.0, (float) ProductSegment::find($retailId)->allocation_percentages[0], 0.001);   // same row, updated
    }

    public function test_the_agreed_example_gives_the_agreed_sales(): void
    {
        $project = $this->study();
        $this->saved($project);
        $valve = $this->valve($project);

        $this->assertEqualsWithDelta(33000000, $this->year($this->segment($project, 'local', 'Retail')->monthly_values), 1);
        $this->assertEqualsWithDelta(18000000, $this->year($this->segment($project, 'local', 'Wholesale')->monthly_values), 1);
        $this->assertEqualsWithDelta(11400000, $this->year($this->segment($project, 'local', 'Distributors')->monthly_values), 1);
        $this->assertEqualsWithDelta(34500000, $this->year($this->segment($project, 'export', 'Saudi Arabia')->monthly_values), 1);
        $this->assertEqualsWithDelta(19800000, $this->year($this->segment($project, 'export', 'UAE')->monthly_values), 1);
        $this->assertEqualsWithDelta(12540000, $this->year($this->segment($project, 'export', 'Italy')->monthly_values), 1);

        $local = array_sum(array_map(fn ($n) => $this->year($this->segment($project, 'local', $n)->monthly_values), ['Retail', 'Wholesale', 'Distributors']));
        $this->assertEqualsWithDelta(62400000, $local, 1);
        $this->assertEqualsWithDelta(129240000, $this->year($valve->refresh()->monthly_sales_target_values), 1);

        // The export quantity of Saudi Arabia is 3,000 tons and its price in the study currency is 230 x 50.
        $this->assertEqualsWithDelta(3000, $this->year($this->segment($project, 'export', 'Saudi Arabia')->monthly_quantities), 1e-6);
    }

    public function test_the_yearly_sales_figures_and_the_average_export_price_are_kept_on_the_product(): void
    {
        $project = $this->study();
        $this->saved($project);
        $valve = $this->valve($project);

        $this->assertEqualsWithDelta(129240000, (float) $valve->sales_target_values[0], 1);
        // Average export price = 66,840,000 / 6,000 tons.
        $this->assertEqualsWithDelta(11140, (float) $valve->export_price_per_unit[0], 0.01);
    }

    public function test_the_fx_rate_of_each_year_is_used(): void
    {
        $project = $this->study();
        $this->saved($project);

        // Year 2 (index 1): USD 54, EUR 58.  Saudi Arabia: 3,000 tons x 230 x 54.
        $this->assertEqualsWithDelta(3000 * 230 * 54, $this->year($this->segment($project, 'export', 'Saudi Arabia')->monthly_values, 1), 1);
        $this->assertEqualsWithDelta(1200 * 190 * 58, $this->year($this->segment($project, 'export', 'Italy')->monthly_values, 1), 1);
    }

    public function test_the_foreign_price_grows_year_by_year(): void
    {
        $project = $this->study();
        $rows = $this->marketRows($project);
        $rows[0]['foreign_growth_rates'] = [0 => 0, 1 => 10, 2 => 10, 3 => 0, 4 => 0];
        $this->saved($project, ['segments' => ['local' => $this->channelRows($project), 'export' => $rows]]);

        // USD 230, then 253.00 (+10 %), then 278.30 (+10 %): in EGP at 54 and 58.
        $this->assertEqualsWithDelta(3000 * 253.00 * 54, $this->year($this->segment($project, 'export', 'Saudi Arabia')->monthly_values, 1), 1);
        $this->assertEqualsWithDelta(3000 * 278.30 * 58, $this->year($this->segment($project, 'export', 'Saudi Arabia')->monthly_values, 2), 1);
    }

    // ── two seasonality patterns ────────────────────────────

    public function test_local_and_export_have_their_own_patterns(): void
    {
        $project = $this->study();
        $this->saved($project, ['seasonality' => ['type' => 'quarterly', 'quarterly' => [20, 30, 35, 15]], 'export_seasonality' => ['type' => 'flat']]);
        $valve = $this->valve($project);

        $this->assertSame('quarterly', $valve->seasonality->type);
        $this->assertSame('flat', $valve->exportSeasonality->type);

        // January: Retail 3,000 x 20 % / 3 = 200 tons; Saudi Arabia 3,000 / 12 = 250 tons.
        $this->assertEqualsWithDelta(200, (float) $this->segment($project, 'local', 'Retail')->monthly_quantities[0], 1e-6);
        $this->assertEqualsWithDelta(250, (float) $this->segment($project, 'export', 'Saudi Arabia')->monthly_quantities[0], 1e-6);
        $this->assertEqualsWithDelta(129240000, $this->year($valve->refresh()->monthly_sales_target_values), 1);   // the year's total does not change
    }

    public function test_without_an_export_pattern_export_follows_the_local_one(): void
    {
        $project = $this->study();
        $this->saved($project, ['seasonality' => ['type' => 'quarterly', 'quarterly' => [20, 30, 35, 15]]]);

        // UAE: 1,800 tons a year, January = 20 % / 3 of it.
        $this->assertEqualsWithDelta(1800 * 0.20 / 3, (float) $this->segment($project, 'export', 'UAE')->monthly_quantities[0], 1e-6);
    }

    public function test_the_export_pattern_must_add_up_to_100(): void
    {
        $project = $this->study();

        $this->save($project, ['export_seasonality' => ['type' => 'quarterly', 'quarterly' => [20, 30, 35, 10]]])->assertSessionHasErrors('export_seasonality.quarterly');
    }

    // ── collections ─────────────────────────────────────────

    public function test_a_channel_with_its_own_terms_is_collected_on_them(): void
    {
        $project = $this->study();
        $cash = array_fill(0, 2, ['cash_payment' => 100, 'rate' => [0, 0], 'due_in_days' => [30, 60]]);
        $this->saved($project, ['segments' => [
            'local' => $this->channelRows($project, ['Retail' => ['uses_own_terms' => true, 'collection_policy_value' => $cash]]),
            'export' => $this->marketRows($project),
        ]]);

        $retail = $this->segment($project, 'local', 'Retail');
        $wholesale = $this->segment($project, 'local', 'Wholesale');
        $this->assertTrue((bool) $retail->uses_own_terms);
        $this->assertNotEmpty($retail->collection_statement);
        $this->assertNotEmpty($wholesale->collection_statement);

        // Retail is paid in the month of sale, Wholesale follows the product's terms (20 % cash only): the share collected in January is higher for Retail.
        $retailShare = $retail->collection_statement['monthly']['payment'][0] / $retail->monthly_values[0];
        $wholesaleShare = $wholesale->collection_statement['monthly']['payment'][0] / $wholesale->monthly_values[0];
        $this->assertGreaterThan($wholesaleShare, $retailShare);
    }

    public function test_the_product_statement_is_the_sum_of_its_channels_and_markets(): void
    {
        $project = $this->study();
        $this->saved($project);
        $valve = $this->valve($project);

        $segments = ProductSegment::where('product_id', $valve->id)->get();
        $local = $segments->where('kind', 'local')->sum(fn ($s) => $s->collection_statement['monthly']['expense'][0]);
        $export = $segments->where('kind', 'export')->sum(fn ($s) => $s->collection_statement['monthly']['expense'][0]);

        $this->assertGreaterThan(0, $export);
        $this->assertEqualsWithDelta(129240000 / 12, $local + $export, 1);
    }

    public function test_own_terms_must_add_up_to_100(): void
    {
        $project = $this->study();
        $bad = array_fill(0, 2, ['cash_payment' => 50, 'rate' => [10, 0], 'due_in_days' => [30, 60]]);

        $this->save($project, ['segments' => [
            'local' => $this->channelRows($project, ['Retail' => ['uses_own_terms' => true, 'collection_policy_value' => $bad]]),
            'export' => $this->marketRows($project),
        ]])->assertSessionHasErrors('segments.local.0.collection_policy_value.0');
    }

    // ── the rules (R7) ──────────────────────────────────────

    public function test_the_channel_shares_of_every_year_must_add_up_to_100(): void
    {
        $project = $this->study();
        $rows = $this->channelRows($project);
        $rows[0]['allocation_percentages'] = [0 => 50, 1 => 50, 2 => 40, 3 => 50, 4 => 50];

        $response = $this->save($project, ['segments' => ['local' => $rows, 'export' => $this->marketRows($project)]]);

        $response->assertSessionHasErrors('segments.local');
        $this->assertStringContainsString('2028', session('errors')->first('segments.local'));
        $this->assertStringContainsString('90', session('errors')->first('segments.local'));
        $this->assertSame(0, ProductSegment::where('product_id', $this->valve($project)->id)->count());
    }

    public function test_the_market_shares_of_every_year_must_add_up_to_100(): void
    {
        $project = $this->study();
        $rows = $this->marketRows($project);
        $rows[2]['allocation_percentages'] = $this->each(10);

        $this->save($project, ['segments' => ['local' => $this->channelRows($project), 'export' => $rows]])->assertSessionHasErrors('segments.export');
    }

    public function test_local_quantity_needs_a_channel_and_export_quantity_needs_a_market(): void
    {
        $project = $this->study();

        $this->save($project, ['segments' => ['local' => [], 'export' => $this->marketRows($project)]])->assertSessionHasErrors('segments.local');
        $this->save($project, ['segments' => ['local' => $this->channelRows($project), 'export' => []], 'comment_for_sales' => 'x'])->assertSessionHasErrors('segments.export');
    }

    public function test_a_year_without_export_needs_no_market_shares(): void
    {
        $project = $this->study();
        // Export only in the first two years: the other years are 100 % local.
        $local = [0 => 50, 1 => 50, 2 => 100, 3 => 100, 4 => 100];
        $rows = $this->marketRows($project);
        foreach ($rows as $n => $row) {
            $rows[$n]['allocation_percentages'] = [0 => $row['allocation_percentages'][0], 1 => $row['allocation_percentages'][1], 2 => 0, 3 => 0, 4 => 0];
        }

        $this->saved($project, ['local_target_quantity_percentages' => $local, 'segments' => ['local' => $this->channelRows($project), 'export' => $rows]]);

        $this->assertEqualsWithDelta(0, $this->year($this->segment($project, 'export', 'Italy')->monthly_values, 2), 1e-6);
        $this->assertGreaterThan(0, $this->year($this->segment($project, 'local', 'Retail')->monthly_values, 2));
    }

    public function test_a_market_needs_a_rate_for_every_year_that_exports(): void
    {
        $project = $this->study();
        // Remove the euro rate of the last year directly (the study form would refuse it).
        $fx = $project->fxRates()->where('currency', 'euro')->first();
        $rates = $fx->rates;
        $rates[4] = 0;
        $fx->update(['rates' => $rates]);

        $response = $this->save($project);

        $response->assertSessionHasErrors('segments.export.2.export_market_id');
        $this->assertStringContainsString('Italy', session('errors')->first('segments.export.2.export_market_id'));
    }

    public function test_with_export_off_the_export_share_must_be_zero(): void
    {
        $project = $this->study(['has_export_sales' => false, 'export_markets' => [], 'fx_rates' => []]);

        $this->save($project, ['segments' => ['local' => $this->channelRows($project), 'export' => []]])->assertSessionHasErrors('local_target_quantity_percentages');
    }

    public function test_with_export_off_a_fully_local_product_is_fine(): void
    {
        $project = $this->study(['has_export_sales' => false, 'export_markets' => [], 'fx_rates' => []]);

        $this->saved($project, ['local_target_quantity_percentages' => $this->each(100), 'segments' => ['local' => $this->channelRows($project), 'export' => []]]);

        $this->assertEqualsWithDelta(12000 * 10000 * (0.5 * 1.1 + 0.3 + 0.2 * 0.95), $this->year($this->valve($project)->monthly_sales_target_values), 1);
        $this->assertSame(0, ProductSegment::where('product_id', $this->valve($project)->id)->where('kind', 'export')->count());
    }

    public function test_a_channel_of_another_study_is_refused(): void
    {
        $project = $this->study();
        $mine = $this->owner;
        $this->owner = User::factory()->create();
        $other = $this->study();
        $this->owner = $mine;
        $rows = $this->channelRows($project);
        $rows[0]['sales_channel_id'] = $other->salesChannels()->first()->id;

        $this->save($project, ['segments' => ['local' => $rows, 'export' => $this->marketRows($project)]])->assertSessionHasErrors('segments.local.0.sales_channel_id');
    }

    public function test_the_same_channel_cannot_be_chosen_twice(): void
    {
        $project = $this->study();
        $rows = $this->channelRows($project);
        $rows[1]['sales_channel_id'] = $rows[0]['sales_channel_id'];

        $this->save($project, ['segments' => ['local' => $rows, 'export' => $this->marketRows($project)]])->assertSessionHasErrors('segments.local.1.sales_channel_id');
    }

    public function test_the_messages_come_in_arabic_too(): void
    {
        $project = $this->study();
        $rows = $this->channelRows($project);
        $rows[0]['allocation_percentages'] = $this->each(10);

        $this->owner->update(['language' => 'ar']);
        $response = $this->save($project, ['segments' => ['local' => $rows, 'export' => $this->marketRows($project)]]);

        $response->assertSessionHasErrors('segments.local');
        $this->assertStringContainsString('100%', session('errors')->first('segments.local'));
        $this->assertStringContainsString('قنوات', session('errors')->first('segments.local'));
    }

    // ── the old way keeps working (R8) ──────────────────────

    public function test_a_study_with_no_channels_or_markets_is_calculated_as_before(): void
    {
        $project = $this->study(null);
        $this->actingAs($this->owner)->put(route('projects.products.update', [$project, $this->valve($project)]), [
            'max_capacity' => $this->each(12000), 'target_percentages' => $this->each(100), 'local_target_quantity_percentages' => $this->each(50),
            'local_growth_rates' => $this->each(0), 'local_price_per_unit' => $this->each(10000), 'export_growth_rates' => $this->each(0), 'export_price_per_unit' => $this->each(11000),
            'seasonality' => ['type' => 'flat'], 'fg_inventory_quantity' => 0, 'fg_inventory_value' => 0, 'fg_inventory_coverage_days' => 0,
            'collection_policy_value' => ['local' => array_fill(0, 2, ['cash_payment' => 100, 'rate' => [0, 0], 'due_in_days' => [30, 60]]), 'export' => array_fill(0, 2, ['cash_payment' => 100, 'rate' => [0, 0], 'due_in_days' => [30, 60]])],
            'rawMaterials' => [], 'after' => 'stay',
        ])->assertSessionHasNoErrors()->assertSessionMissing('error')->assertRedirect();

        $this->assertSame(0, ProductSegment::where('product_id', $this->valve($project)->id)->count());
        $this->assertEqualsWithDelta(6000 * 10000 + 6000 * 11000, $this->year($this->valve($project)->monthly_sales_target_values), 1);
    }

    public function test_a_save_that_does_not_send_segments_keeps_the_saved_ones(): void
    {
        $project = $this->study();
        $this->saved($project);

        $payload = $this->payload($project, ['comment_for_sales' => 'again']);
        unset($payload['segments']);
        $this->actingAs($this->owner)->put(route('projects.products.update', [$project, $this->valve($project)]), $payload)->assertSessionHasNoErrors()->assertSessionMissing('error');

        $this->assertSame(6, ProductSegment::where('product_id', $this->valve($project)->id)->count());
        $this->assertEqualsWithDelta(129240000, $this->year($this->valve($project)->monthly_sales_target_values), 1);
    }

    // ── the FX what-if ──────────────────────────────────────

    private function sensitivity(Project $project, float $fx, int $days = 0)
    {
        return $this->actingAs($this->owner)->put(route('projects.sensitivity.update', $project), [
            'rows' => [['id' => $this->valve($project)->id, 'target' => 0, 'rm' => 0, 'days' => $days]], 'fx' => $fx,
        ]);
    }

    private function sensitivityProps(Project $project): array
    {
        $page = $this->actingAs($this->owner)->get(route('projects.sensitivity', $project))->assertOk()->viewData('page');

        return json_decode(is_string($page) ? $page : json_encode($page), true)['props'];
    }

    public function test_a_stronger_foreign_currency_raises_export_revenue_only(): void
    {
        $project = $this->study();
        $this->saved($project);
        // the 60 months of the study (the saved figures hold one extra year)
        $exportAll = ProductSegment::where('product_id', $this->valve($project)->id)->where('kind', 'export')->get()->sum(fn ($s) => array_sum(array_slice((array) $s->monthly_values, 0, 60, true)));
        $before = $project->fxRates()->orderBy('id')->get()->map->only(['currency', 'rates'])->all();

        $this->sensitivity($project, 10)->assertSessionHasNoErrors();
        $props = $this->sensitivityProps($project);

        $this->assertTrue($props['hasFx']);
        $this->assertEqualsWithDelta(10.0, (float) $props['fx'], 0.001);
        $this->assertTrue($props['compared']);
        $this->assertEqualsWithDelta(0.10 * $exportAll, array_sum($props['scenario']['revenue']['value']) - array_sum($props['base']['revenue']['value']), 5);

        // The study is as it was.
        $this->assertEquals($before, $project->fxRates()->orderBy('id')->get()->map->only(['currency', 'rates'])->all());
        $this->assertEqualsWithDelta(129240000, $this->year($this->valve($project)->monthly_sales_target_values), 1);
    }

    public function test_a_weaker_foreign_currency_lowers_export_revenue(): void
    {
        $project = $this->study();
        $this->saved($project);

        $this->sensitivity($project, -20)->assertSessionHasNoErrors();
        $props = $this->sensitivityProps($project);

        $this->assertLessThan(array_sum($props['base']['revenue']['value']), array_sum($props['scenario']['revenue']['value']));
    }

    public function test_a_what_if_with_only_fx_is_a_comparison(): void
    {
        $project = $this->study();
        $this->saved($project);

        $this->sensitivity($project, 5)->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(5.0, (float) Sensitivity::where('project_id', $project->id)->value('fx_rate_change'), 0.001);
        $this->assertTrue($this->sensitivityProps($project)['compared']);
    }

    public function test_the_fx_field_is_only_offered_when_there_is_export_with_rates(): void
    {
        $project = $this->study(null);

        $page = $this->actingAs($this->owner)->get(route('projects.sensitivity', $project))->assertOk()->viewData('page');
        $props = json_decode(is_string($page) ? $page : json_encode($page), true)['props'];

        $this->assertFalse($props['hasFx']);
    }

    public function test_the_fx_change_is_limited(): void
    {
        $project = $this->study();

        $this->sensitivity($project, 1500)->assertSessionHasErrors('fx');
        $this->sensitivity($project, -100)->assertSessionHasErrors('fx');
    }

    public function test_later_collection_also_delays_the_own_terms_of_a_channel(): void
    {
        $project = $this->study();
        $cash = array_fill(0, 2, ['cash_payment' => 100, 'rate' => [0, 0], 'due_in_days' => [30, 60]]);
        $this->saved($project, ['segments' => [
            'local' => $this->channelRows($project, ['Retail' => ['uses_own_terms' => true, 'collection_policy_value' => $cash]]),
            'export' => $this->marketRows($project),
        ]]);
        $retailTerms = $this->segment($project, 'local', 'Retail')->collection_policy_value;

        $this->sensitivity($project, 0, 60)->assertSessionHasNoErrors();
        $props = $this->sensitivityProps($project);

        $this->assertGreaterThan(array_sum($props['base']['ratios']['dso']), array_sum($props['scenario']['ratios']['dso']));
        $this->assertEquals($retailTerms, $this->segment($project, 'local', 'Retail')->collection_policy_value);   // saved terms untouched
    }

    // ── size ────────────────────────────────────────────────

    public function test_the_largest_study_8_channels_10_markets_6_currencies_is_recalculated_in_reasonable_time(): void
    {
        $currencies = ['usd', 'euro', 'sar', 'aed', 'qar'];   // the study currency (egp) is the sixth
        $fx = array_map(fn ($c) => ['currency' => $c, 'rates' => [0 => 50, 1 => 52, 2 => 54, 3 => 56, 4 => 58]], $currencies);
        $markets = array_map(fn ($i) => ['name' => "Market $i", 'currency' => $currencies[$i % 5]], range(0, 9));
        $channels = array_map(fn ($i) => ['name' => "Channel $i"], range(0, 7));

        $project = $this->study(['sales_channels' => $channels, 'fx_rates' => $fx, 'export_markets' => $markets]);
        $local = $project->salesChannels()->orderBy('position')->get()->map(fn ($c, $i) => ['sales_channel_id' => $c->id, 'allocation_percentages' => $this->each(12.5), 'price_differences' => $this->each($i), 'uses_own_terms' => false])->all();
        $export = $project->exportMarkets()->orderBy('position')->get()->map(fn ($m, $i) => ['export_market_id' => $m->id, 'allocation_percentages' => $this->each(10), 'first_foreign_price' => 200 + $i, 'foreign_growth_rates' => $this->each(2), 'uses_own_terms' => false])->all();

        $started = microtime(true);
        $this->saved($project, ['segments' => ['local' => $local, 'export' => $export]]);
        $seconds = microtime(true) - $started;

        $this->assertSame(18, ProductSegment::where('product_id', $this->valve($project)->id)->count());
        $this->assertLessThan(15, $seconds, "saving and recalculating took $seconds s");
    }
}
