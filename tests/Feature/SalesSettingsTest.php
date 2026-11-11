<?php

namespace Tests\Feature;

use App\Models\ExportMarket;
use App\Models\FxRate;
use App\Models\Product;
use App\Models\ProductSegment;
use App\Models\Project;
use App\Models\SalesChannel;
use App\Models\Seasonality;
use App\Models\User;
use App\Services\ProjectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

// Product sales rebuild, Stage A: the study's export switch, foreign currencies with FX forecast, sales channels and export markets.
// Run:  php artisan test --filter=SalesSettingsTest
class SalesSettingsTest extends TestCase
{
    use RefreshDatabase;

    /** A complete, valid study form (5 years from 2026-01, so the FX forecast has years 0 to 4). */
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
            'operation_start_date'     => '2026-01',
            'tax_rate'                 => 22.5,
            'return_rate'              => 15,
            'perpetual_growth_rate'    => 3,
            'products'                 => [['name' => 'Valve', 'measurement_unit' => 'piece', 'selling_start_date' => '2026-01', 'vat_rate' => 14, 'withhold_tax_rate' => 1]],
            'rawMaterials'             => [['name' => 'Brass', 'rm_inventory_coverage_days' => 30, 'measurement_unit' => 'kg', 'vat_rate' => 14, 'withhold_tax_rate' => 0]],
        ], $override);
    }

    /** The sales part of the form: export on, USD and EUR, two channels, three markets. */
    private function sales(array $override = []): array
    {
        return array_merge([
            'has_export_sales' => true,
            'sales_channels'   => [['name' => 'Retail'], ['name' => 'Wholesale'], ['name' => 'Distributors']],
            'fx_rates'         => [
                ['currency' => 'usd', 'rates' => [0 => 50, 1 => 54, 2 => 58, 3 => 60, 4 => 62]],
                ['currency' => 'euro', 'rates' => [0 => 55, 1 => 58, 2 => 61, 3 => 63, 4 => 65]],
            ],
            'export_markets'   => [
                ['name' => 'Saudi Arabia', 'currency' => 'usd'],
                ['name' => 'UAE', 'currency' => 'usd'],
                ['name' => 'Italy', 'currency' => 'euro'],
            ],
        ], $override);
    }

    /** Saves the study form. The saved product and raw material are sent with their ids, like the screen does (otherwise they would be replaced). */
    private function saveStudy(User $user, Project $project, array $sales = [], array $form = [])
    {
        $data = $this->form($form);
        if (! array_key_exists('products', $form) && ($id = $project->products()->value('id'))) {
            $data['products'][0]['id'] = $id;
        }
        if (! array_key_exists('rawMaterials', $form) && ($id = $project->rawMaterials()->value('id'))) {
            $data['rawMaterials'][0]['id'] = $id;
        }

        return $this->actingAs($user)->put(route('projects.update', $project), $data + $sales);
    }

    /** A saved study with the sales settings above. */
    private function study(User $user): Project
    {
        $project = app(ProjectService::class)->create($user, 'Plant A');
        $this->saveStudy($user, $project, $this->sales())->assertSessionHasNoErrors()->assertSessionMissing('error')->assertRedirect();

        return $project->refresh();
    }

    // ── saving ──────────────────────────────────────────────

    public function test_a_new_study_has_no_export_sales(): void
    {
        $me = User::factory()->create();
        $project = app(ProjectService::class)->create($me, 'Plant A');

        $this->assertFalse($project->refresh()->has_export_sales);
        $this->assertSame(0, $project->salesChannels()->count());
        $this->assertSame(0, $project->exportMarkets()->count());
        $this->assertSame(0, $project->fxRates()->count());
    }

    public function test_the_toggle_currencies_fx_channels_and_markets_are_saved(): void
    {
        $me = User::factory()->create();
        $project = $this->study($me);

        $this->assertTrue($project->has_export_sales);
        $this->assertSame(['Retail', 'Wholesale', 'Distributors'], $project->salesChannels->pluck('name')->all());
        $this->assertSame(['Saudi Arabia', 'UAE', 'Italy'], $project->exportMarkets->pluck('name')->all());
        $this->assertSame(['usd', 'usd', 'euro'], $project->exportMarkets->pluck('currency')->all());

        $usd = $project->fxRates->firstWhere('currency', 'usd');
        $this->assertEquals([0 => 50, 1 => 54, 2 => 58, 3 => 60, 4 => 62], $usd->rates);
        $this->assertSame(2, $project->fxRates->count());
    }

    public function test_the_form_shows_what_was_saved(): void
    {
        $me = User::factory()->create();
        $project = $this->study($me);

        $this->actingAs($me)->get(route('projects.edit', $project))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Projects/Edit')
            ->where('sales.has_export_sales', true)
            ->has('sales.channels', 3)
            ->where('sales.channels.0.name', 'Retail')
            ->has('sales.markets', 3)
            ->where('sales.markets.2.currency', 'euro')
            ->has('sales.fx', 2)
            ->where('sales.fx.0.currency', 'usd')
            ->where('sales.fx.0.rates.1', fn ($rate) => (float) $rate === 54.0)
            ->where('options.maxChannels', 8)
            ->where('options.maxMarkets', 10)
            ->where('options.maxCurrencies', 6));
    }

    public function test_saving_again_keeps_the_same_rows_and_follows_the_order_and_names(): void
    {
        $me = User::factory()->create();
        $project = $this->study($me);
        [$retail, $wholesale] = $project->salesChannels;

        $this->saveStudy($me, $project, $this->sales([
            'sales_channels' => [['id' => $wholesale->id, 'name' => 'Wholesale clients'], ['id' => $retail->id, 'name' => 'Retail']],
        ]))->assertSessionHasNoErrors()->assertSessionMissing('error')->assertRedirect();

        $channels = $project->refresh()->salesChannels;
        $this->assertSame([$wholesale->id, $retail->id], $channels->pluck('id')->all());
        $this->assertSame(['Wholesale clients', 'Retail'], $channels->pluck('name')->all());
    }

    public function test_two_channels_can_swap_names_and_a_new_one_can_reuse_a_removed_name(): void
    {
        $me = User::factory()->create();
        $project = $this->study($me);
        [$retail, $wholesale] = $project->salesChannels;

        // Retail and Wholesale swap names; Distributors is removed and typed again as a new row.
        $this->saveStudy($me, $project, $this->sales([
            'sales_channels' => [['id' => $retail->id, 'name' => 'Wholesale'], ['id' => $wholesale->id, 'name' => 'Retail'], ['name' => 'Distributors']],
        ]))->assertSessionHasNoErrors()->assertSessionMissing('error')->assertRedirect();

        $channels = $project->salesChannels()->get();
        $this->assertSame(['Wholesale', 'Retail', 'Distributors'], $channels->pluck('name')->all());
        $this->assertSame([$retail->id, $wholesale->id], $channels->take(2)->pluck('id')->all());
    }

    public function test_a_form_that_does_not_send_the_sales_settings_leaves_them_as_they_are(): void
    {
        $me = User::factory()->create();
        $project = $this->study($me);

        $this->saveStudy($me, $project)->assertSessionHasNoErrors()->assertSessionMissing('error')->assertRedirect();   // no sales keys at all

        $project->refresh();
        $this->assertTrue($project->has_export_sales);
        $this->assertSame(3, $project->salesChannels()->count());
        $this->assertSame(3, $project->exportMarkets()->count());
        $this->assertSame(2, $project->fxRates()->count());
    }

    public function test_a_currency_that_is_no_longer_chosen_is_removed(): void
    {
        $me = User::factory()->create();
        $project = $this->study($me);

        $this->saveStudy($me, $project, $this->sales([
            'fx_rates'       => [['currency' => 'usd', 'rates' => [0 => 50, 1 => 54, 2 => 58, 3 => 60, 4 => 62]]],
            'export_markets' => [['name' => 'Saudi Arabia', 'currency' => 'usd']],
        ]))->assertSessionHasNoErrors()->assertSessionMissing('error')->assertRedirect();

        $this->assertSame(['usd'], $project->fxRates()->pluck('currency')->all());
        $this->assertSame(1, $project->exportMarkets()->count());
    }

    // ── checks (R1, R7) ─────────────────────────────────────

    public function test_the_study_currency_cannot_be_a_foreign_currency(): void
    {
        $me = User::factory()->create();
        $project = app(ProjectService::class)->create($me, 'Plant A');

        $this->saveStudy($me, $project, $this->sales(['fx_rates' => [['currency' => 'egp', 'rates' => [0 => 1, 1 => 1, 2 => 1, 3 => 1, 4 => 1]]], 'export_markets' => []]))
            ->assertSessionHasErrors('fx_rates.0.currency');
    }

    public function test_every_foreign_currency_needs_a_rate_above_zero_for_every_year(): void
    {
        $me = User::factory()->create();
        $project = app(ProjectService::class)->create($me, 'Plant A');

        // 2028 missing and 2029 zero.
        $this->saveStudy($me, $project, $this->sales(['fx_rates' => [['currency' => 'usd', 'rates' => [0 => 50, 1 => 54, 3 => 0, 4 => 62]]], 'export_markets' => []]))
            ->assertSessionHasErrors(['fx_rates.0.rates.2', 'fx_rates.0.rates.3']);

        $this->assertSame(0, $project->fxRates()->count());
    }

    public function test_export_sales_need_at_least_one_foreign_currency(): void
    {
        $me = User::factory()->create();
        $project = app(ProjectService::class)->create($me, 'Plant A');

        $this->saveStudy($me, $project, ['has_export_sales' => true])->assertSessionHasErrors('fx_rates');
    }

    public function test_a_market_must_use_a_currency_chosen_in_the_study(): void
    {
        $me = User::factory()->create();
        $project = app(ProjectService::class)->create($me, 'Plant A');

        $this->saveStudy($me, $project, $this->sales([
            'fx_rates'       => [['currency' => 'usd', 'rates' => [0 => 50, 1 => 54, 2 => 58, 3 => 60, 4 => 62]]],
            'export_markets' => [['name' => 'Italy', 'currency' => 'euro']],
        ]))->assertSessionHasErrors('export_markets.0.currency');
    }

    public function test_with_export_off_the_fx_rates_are_not_demanded(): void
    {
        $me = User::factory()->create();
        $project = app(ProjectService::class)->create($me, 'Plant A');

        $this->saveStudy($me, $project, ['has_export_sales' => false, 'sales_channels' => [['name' => 'Retail']]])->assertSessionHasNoErrors()->assertSessionMissing('error')->assertRedirect();

        $this->assertFalse($project->refresh()->has_export_sales);
        $this->assertSame(1, $project->salesChannels()->count());
    }

    public function test_names_are_unique_whatever_the_capital_letters(): void
    {
        $me = User::factory()->create();
        $project = app(ProjectService::class)->create($me, 'Plant A');

        $this->saveStudy($me, $project, ['sales_channels' => [['name' => 'Retail'], ['name' => ' retail ']]])->assertSessionHasErrors('sales_channels.1.name');
        $this->saveStudy($me, $project, $this->sales(['export_markets' => [['name' => 'Italy', 'currency' => 'euro'], ['name' => 'ITALY', 'currency' => 'euro']]]))->assertSessionHasErrors('export_markets.1.name');
        $this->assertSame(0, $project->salesChannels()->count());
    }

    public function test_a_name_is_required(): void
    {
        $me = User::factory()->create();
        $project = app(ProjectService::class)->create($me, 'Plant A');

        $this->saveStudy($me, $project, ['sales_channels' => [['name' => '   ']]])->assertSessionHasErrors('sales_channels.0.name');
    }

    public function test_the_limits_are_eight_channels_ten_markets_and_six_currencies(): void
    {
        $me = User::factory()->create();
        $project = app(ProjectService::class)->create($me, 'Plant A');

        $this->saveStudy($me, $project, ['sales_channels' => array_map(fn ($i) => ['name' => "Channel {$i}"], range(1, 8))])->assertSessionHasNoErrors()->assertSessionMissing('error')->assertRedirect();
        $this->saveStudy($me, $project, ['sales_channels' => array_map(fn ($i) => ['name' => "Channel {$i}"], range(1, 9))])->assertSessionHasErrors('sales_channels');
        $this->assertSame(8, $project->salesChannels()->count());

        $markets = array_map(fn ($i) => ['name' => "Market {$i}", 'currency' => 'usd'], range(1, 11));
        $this->saveStudy($me, $project, $this->sales(['export_markets' => $markets]))->assertSessionHasErrors('export_markets');
    }

    // ── in use / switching off ──────────────────────────────

    public function test_a_channel_or_market_used_by_a_product_cannot_be_deleted(): void
    {
        $me = User::factory()->create();
        $project = $this->study($me);
        $product = $project->products()->firstOrFail();
        $retail = $project->salesChannels->first();
        $saudi = $project->exportMarkets->first();

        ProductSegment::create(['project_id' => $project->id, 'product_id' => $product->id, 'kind' => 'local', 'sales_channel_id' => $retail->id, 'allocation_percentages' => [0 => 100]]);
        ProductSegment::create(['project_id' => $project->id, 'product_id' => $product->id, 'kind' => 'export', 'export_market_id' => $saudi->id, 'allocation_percentages' => [0 => 100]]);

        $channels = $project->salesChannels->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->all();
        $markets = $project->exportMarkets->map(fn ($m) => ['id' => $m->id, 'name' => $m->name, 'currency' => $m->currency])->all();

        // Retail removed while Valve uses it: refused, and the message names the product.
        $response = $this->saveStudy($me, $project, $this->sales(['sales_channels' => array_slice($channels, 1), 'export_markets' => $markets]));
        $response->assertSessionHasErrors('sales_channels');
        $this->assertStringContainsString('Valve', session('errors')->first('sales_channels'));
        $this->assertSame(3, $project->salesChannels()->count());

        // Saudi Arabia removed while Valve uses it: refused too.
        $this->saveStudy($me, $project, $this->sales(['sales_channels' => $channels, 'export_markets' => array_slice($markets, 1)]))->assertSessionHasErrors('export_markets');
        $this->assertSame(3, $project->exportMarkets()->count());

        // A channel and a market nobody uses can go.
        $this->saveStudy($me, $project, $this->sales(['sales_channels' => array_slice($channels, 0, 2), 'export_markets' => [$markets[0], $markets[2]]]))->assertSessionHasNoErrors()->assertSessionMissing('error')->assertRedirect();
        $this->assertSame(2, $project->salesChannels()->count());
        $this->assertSame(2, $project->exportMarkets()->count());
    }

    public function test_switching_export_off_needs_confirmation_while_a_product_exports(): void
    {
        $me = User::factory()->create();
        $project = $this->study($me);
        $this->giveExportQuantity($project->products()->firstOrFail());

        $this->saveStudy($me, $project, ['has_export_sales' => false])->assertSessionHasErrors('has_export_sales');
        $this->assertTrue($project->refresh()->has_export_sales);
        $this->assertGreaterThan(0, (float) $project->products()->firstOrFail()->export_target_quantities[0]);
    }

    public function test_confirming_export_off_sets_every_product_export_to_zero(): void
    {
        $me = User::factory()->create();
        $project = $this->study($me);
        $product = $this->giveExportQuantity($project->products()->firstOrFail());
        ProductSegment::create(['project_id' => $project->id, 'product_id' => $product->id, 'kind' => 'export', 'export_market_id' => $project->exportMarkets->first()->id, 'allocation_percentages' => [0 => 100]]);
        ProductSegment::create(['project_id' => $project->id, 'product_id' => $product->id, 'kind' => 'local', 'sales_channel_id' => $project->salesChannels->first()->id, 'allocation_percentages' => [0 => 100]]);

        $this->saveStudy($me, $project, ['has_export_sales' => false, 'clear_export' => true])->assertSessionHasNoErrors()->assertSessionMissing('error')->assertRedirect();

        $product->refresh();
        $this->assertFalse($project->refresh()->has_export_sales);
        $this->assertEquals([0 => 0, 1 => 0], $product->export_target_quantities);
        $this->assertEquals([0 => 1000, 1 => 1200], $product->local_target_quantities);
        $this->assertEquals([0 => 100, 1 => 100], $product->local_target_quantity_percentages);
        $this->assertEquals([0 => 1000 * 10, 1 => 1200 * 10], $product->sales_target_values);
        // Export allocations are gone, local ones stay.
        $this->assertSame(0, ProductSegment::where('product_id', $product->id)->where('kind', 'export')->count());
        $this->assertSame(1, ProductSegment::where('product_id', $product->id)->where('kind', 'local')->count());
    }

    /** Capacity 1000 / 1200, 100 % sold, half of it exported, local price 10. */
    private function giveExportQuantity(Product $product): Product
    {
        $product->update([
            'target_quantities'                  => [0 => 1000, 1 => 1200],
            'local_target_quantity_percentages'  => [0 => 50, 1 => 50],
            'local_target_quantities'            => [0 => 500, 1 => 600],
            'export_target_quantity_percentages' => [0 => 50, 1 => 50],
            'export_target_quantities'           => [0 => 500, 1 => 600],
            'local_price_per_unit'               => [0 => 10, 1 => 10],
        ]);

        return $product->refresh();
    }

    // ── copy, delete, seasonality ───────────────────────────

    public function test_copying_a_study_copies_the_sales_settings_and_remaps_the_allocations(): void
    {
        $me = User::factory()->create();
        $project = $this->study($me);
        $product = $project->products()->firstOrFail();
        ProductSegment::create(['project_id' => $project->id, 'product_id' => $product->id, 'kind' => 'local', 'sales_channel_id' => $project->salesChannels->first()->id, 'allocation_percentages' => [0 => 100], 'price_differences' => [0 => 10]]);
        ProductSegment::create(['project_id' => $project->id, 'product_id' => $product->id, 'kind' => 'export', 'export_market_id' => $project->exportMarkets->last()->id, 'allocation_percentages' => [0 => 100], 'first_foreign_price' => 190.5]);

        $copy = app(ProjectService::class)->copy($project, 'Plant A (copy)', $me);

        $this->assertTrue($copy->has_export_sales);
        $this->assertSame(['Retail', 'Wholesale', 'Distributors'], $copy->salesChannels->pluck('name')->all());
        $this->assertSame(['Saudi Arabia', 'UAE', 'Italy'], $copy->exportMarkets->pluck('name')->all());
        $this->assertSame(2, $copy->fxRates()->count());
        $this->assertEquals([0 => 55, 1 => 58, 2 => 61, 3 => 63, 4 => 65], $copy->fxRates->firstWhere('currency', 'euro')->rates);

        // The copied rows are new rows, and the copied segments point to the COPIED product, channel and market.
        $this->assertEmpty(array_intersect($project->salesChannels->pluck('id')->all(), $copy->salesChannels->pluck('id')->all()));
        $copyProduct = $copy->products()->firstOrFail();
        $segments = ProductSegment::where('project_id', $copy->id)->orderBy('id')->get();
        $this->assertCount(2, $segments);
        $this->assertSame($copyProduct->id, $segments[0]->product_id);
        $this->assertSame($copy->salesChannels->first()->id, $segments[0]->sales_channel_id);
        $this->assertEquals([0 => 10], $segments[0]->price_differences);
        $this->assertSame($copy->exportMarkets->last()->id, $segments[1]->export_market_id);
        $this->assertEquals('190.50', $segments[1]->first_foreign_price);

        // The original is untouched.
        $this->assertSame(2, ProductSegment::where('project_id', $project->id)->count());
    }

    public function test_deleting_a_study_for_good_removes_its_sales_settings(): void
    {
        $me = User::factory()->create();
        $project = $this->study($me);
        $product = $project->products()->firstOrFail();
        ProductSegment::create(['project_id' => $project->id, 'product_id' => $product->id, 'kind' => 'local', 'sales_channel_id' => $project->salesChannels->first()->id, 'allocation_percentages' => [0 => 100]]);

        // Segments go first (they point to channels and markets), then the study with the rest.
        $project->forceDelete();

        $this->assertSame(0, SalesChannel::count());
        $this->assertSame(0, ExportMarket::count());
        $this->assertSame(0, FxRate::count());
        $this->assertSame(0, ProductSegment::count());
    }

    public function test_a_product_has_a_local_and_an_export_pattern(): void
    {
        $me = User::factory()->create();
        $project = $this->study($me);
        $product = $project->products()->firstOrFail();

        Seasonality::create(['model_id' => $product->id, 'model_name' => 'Product', 'type' => 'flat', 'percentages' => [], 'distributed_percentages' => [], 'project_id' => $project->id]);
        Seasonality::create(['model_id' => $product->id, 'model_name' => 'Product', 'scope' => 'export', 'type' => 'quarterly', 'percentages' => [10, 20, 30, 40], 'distributed_percentages' => [], 'project_id' => $project->id]);

        $product = $product->fresh();
        $this->assertSame('local', $product->seasonality->scope);   // a pattern saved without a scope is the local one
        $this->assertSame('flat', $product->seasonality->type);
        $this->assertSame('export', $product->exportSeasonality->scope);
        $this->assertSame('quarterly', $product->exportSeasonality->type);

        // Both patterns follow the product into a copy of the study.
        $copy = app(ProjectService::class)->copy($project, 'Copy', $me);
        $this->assertSame(2, Seasonality::where('project_id', $copy->id)->count());
        $this->assertSame('quarterly', $copy->products()->firstOrFail()->exportSeasonality->type);
    }

    public function test_a_person_who_may_only_look_cannot_change_the_sales_settings(): void
    {
        $me = User::factory()->create();
        $project = app(ProjectService::class)->create($me, 'Plant A');
        $colleague = User::factory()->create(['company_id' => $me->company_id]);
        $project->shares()->create(['user_id' => $colleague->id, 'can_edit' => false]);

        $this->saveStudy($colleague, $project, $this->sales())->assertForbidden();
        $this->assertSame(0, $project->salesChannels()->count());
    }
}
