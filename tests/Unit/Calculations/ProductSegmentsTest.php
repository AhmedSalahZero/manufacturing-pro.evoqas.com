<?php

namespace Tests\Unit\Calculations;

use App\Calculations\CollectionPolicy;
use App\Calculations\ProductSalesCalculator;
use App\Calculations\SeasonalityCalculator;
use App\Calculations\SegmentCalculator;
use App\Calculations\Timeline;
use App\Services\SensitivityService;
use PHPUnit\Framework\TestCase;

// Product sales rebuild, Stage B: sales channels (local) and export markets (foreign currency), two seasonality patterns, collections
// per channel and market, and the average price. The worked examples are those of the scope of work (section 4): capacity 12,000 tons,
// local 6,000 + export 6,000, FX 1 USD = 50 EGP and 1 EUR = 55 EGP.
// Run:  php artisan test --filter=ProductSegmentsTest
class ProductSegmentsTest extends TestCase
{
    private function timeline(): Timeline
    {
        return Timeline::forStudy('2026-01-01', 1, '2026-01-01');   // 24 months: the study year and the extended year
    }

    /** One year's figures, repeated for the extended year like the product form saves them. */
    private function years(float|int $value): array
    {
        return [0 => $value, 1 => $value];
    }

    /** @return array<string,array{quantities:array<int,float>,prices:array<int,float>}> Retail 50% +10%, Wholesale 30% 0%, Distributors 20% -5% of 6,000 tons at 10,000. */
    private function channels(): array
    {
        $result = [];
        foreach (['Retail' => [50, 10], 'Wholesale' => [30, 0], 'Distributors' => [20, -5]] as $name => [$share, $difference]) {
            $result[$name] = [
                'quantities' => SegmentCalculator::extend(SegmentCalculator::quantities([0 => 6000], [0 => $share])),
                'prices'     => SegmentCalculator::extend(SegmentCalculator::localPrices([0 => 10000], [0 => $difference])),
            ];
        }

        return $result;
    }

    /** @return array<string,array{quantities:array<int,float>,prices:array<int,float>}> Saudi Arabia 50% USD 230, UAE 30% USD 220, Italy 20% EUR 190. */
    private function markets(float $usd = 50, float $eur = 55): array
    {
        $result = [];
        foreach (['Saudi Arabia' => [50, 230, $usd], 'UAE' => [30, 220, $usd], 'Italy' => [20, 190, $eur]] as $name => [$share, $price, $rate]) {
            $result[$name] = [
                'quantities' => SegmentCalculator::extend(SegmentCalculator::quantities([0 => 6000], [0 => $share])),
                'prices'     => SegmentCalculator::extend(SegmentCalculator::exportPrices([0 => $price], [0 => $rate])),
            ];
        }

        return $result;
    }

    private function input(array $override = []): array
    {
        return array_merge([
            'selling_start_index'   => 0,
            'seasonality_weights'   => SeasonalityCalculator::monthWeights('flat'),
            'local_quantities'      => $this->years(6000), 'local_prices' => $this->years(10000),
            'export_quantities'     => $this->years(6000), 'export_prices' => $this->years(0),
            'local_segments'        => $this->channels(),
            'export_segments'       => $this->markets(),
            'fg_beginning_quantity' => 0,
            'fg_coverage_days'      => 0,
            'recipes'               => [],
        ], $override);
    }

    private function year(array $monthly, int $year = 0): float
    {
        return array_sum(array_slice($monthly, $year * 12, 12, true));
    }

    // ── the worked examples ─────────────────────────────────

    public function test_the_local_channels_give_the_agreed_prices_and_values(): void
    {
        $this->assertEqualsWithDelta(11000, $this->channels()['Retail']['prices'][0], 1e-9);
        $this->assertEqualsWithDelta(10000, $this->channels()['Wholesale']['prices'][0], 1e-9);
        $this->assertEqualsWithDelta(9500, $this->channels()['Distributors']['prices'][0], 1e-9);

        $result = ProductSalesCalculator::calculate($this->timeline(), $this->input());

        $this->assertEqualsWithDelta(33000000, $this->year($result['segments']['local']['Retail']['values']), 0.01);
        $this->assertEqualsWithDelta(18000000, $this->year($result['segments']['local']['Wholesale']['values']), 0.01);
        $this->assertEqualsWithDelta(11400000, $this->year($result['segments']['local']['Distributors']['values']), 0.01);
        $this->assertEqualsWithDelta(62400000, $this->year($result['local_values']), 0.01);
        $this->assertEqualsWithDelta(6000, $this->year($result['local_quantities']), 1e-6);
        $this->assertEqualsWithDelta(3000, $this->year($result['segments']['local']['Retail']['quantities']), 1e-6);
    }

    public function test_the_export_markets_are_worked_out_in_the_foreign_currency_and_converted(): void
    {
        $this->assertEqualsWithDelta(11500, $this->markets()['Saudi Arabia']['prices'][0], 1e-9);   // 230 USD x 50
        $this->assertEqualsWithDelta(10450, $this->markets()['Italy']['prices'][0], 1e-9);          // 190 EUR x 55

        $result = ProductSalesCalculator::calculate($this->timeline(), $this->input());

        $this->assertEqualsWithDelta(34500000, $this->year($result['segments']['export']['Saudi Arabia']['values']), 0.01);
        $this->assertEqualsWithDelta(19800000, $this->year($result['segments']['export']['UAE']['values']), 0.01);
        $this->assertEqualsWithDelta(12540000, $this->year($result['segments']['export']['Italy']['values']), 0.01);
        $this->assertEqualsWithDelta(66840000, $this->year($result['export_values']), 0.01);

        // Value in the foreign currency, the way the screen shows it: quantity x foreign price.
        $this->assertEqualsWithDelta(690000, 3000 * 230, 1e-9);
        $this->assertEqualsWithDelta(228000, 1200 * 190, 1e-9);
    }

    public function test_the_whole_product_totals_and_the_average_price_that_feeds_raw_materials(): void
    {
        $result = ProductSalesCalculator::calculate($this->timeline(), $this->input(['recipes' => [7 => [0 => 10, 1 => 10]]]));

        $this->assertEqualsWithDelta(129240000, $this->year($result['values']), 0.01);
        $this->assertEqualsWithDelta(12000, $this->year($result['quantities']), 1e-6);
        $this->assertEqualsWithDelta(10770, $result['average_prices'][0], 1e-9);
        $this->assertEqualsWithDelta(10770, $result['average_prices'][1], 1e-9);   // the extended year repeats the figures

        // Raw material = recipe % x average price x units made: 10% x 10,770 x the 900 units made in January (see the seasonality test).
        $made = $result['inventory']['manufacturing_quantity'][0];
        $this->assertEqualsWithDelta(0.10 * 10770 * $made, $result['raw_material_consumed'][7][0], 1e-6);
    }

    public function test_the_average_price_follows_the_quantities_not_the_number_of_segments(): void
    {
        $segments = [
            ['quantities' => [0 => 100], 'prices' => [0 => 10]],
            ['quantities' => [0 => 300], 'prices' => [0 => 20]],
        ];

        $this->assertEqualsWithDelta(17.5, SegmentCalculator::averagePrice($segments, 0), 1e-12);
        $this->assertEqualsWithDelta(7000, SegmentCalculator::totalValue($segments, 0), 1e-9);
        $this->assertEqualsWithDelta(0, SegmentCalculator::averagePrice($segments, 1), 1e-12);   // nothing sold that year
    }

    // ── two seasonality patterns ────────────────────────────

    public function test_local_follows_its_pattern_and_export_follows_the_export_pattern(): void
    {
        $result = ProductSalesCalculator::calculate($this->timeline(), $this->input([
            'seasonality_weights'        => SeasonalityCalculator::monthWeights('quarterly', [20, 30, 35, 15]),
            'export_seasonality_weights' => SeasonalityCalculator::monthWeights('flat'),
        ]));

        // January: 20% / 3 of the local year, 1/12 of the export year; the units to make that month are the sum of both.
        $this->assertEqualsWithDelta(6000 * 0.20 / 3, $result['local_quantities'][0], 1e-9);
        $this->assertEqualsWithDelta(6000 / 12, $result['export_quantities'][0], 1e-9);
        $this->assertEqualsWithDelta(400 + 500, $result['quantities'][0], 1e-9);
        $this->assertEqualsWithDelta(900, $result['inventory']['manufacturing_quantity'][0], 1e-9);

        // Every channel follows the local pattern and every market the export pattern.
        $this->assertEqualsWithDelta(3000 * 0.20 / 3, $result['segments']['local']['Retail']['quantities'][0], 1e-9);
        $this->assertEqualsWithDelta(1200 / 12, $result['segments']['export']['Italy']['quantities'][0], 1e-9);

        // April starts the second quarter (30% / 3 for local).
        $this->assertEqualsWithDelta(6000 * 0.30 / 3, $result['local_quantities'][3], 1e-9);
        $this->assertEqualsWithDelta(6000 / 12, $result['export_quantities'][3], 1e-9);
        // Each pattern still gives the whole year.
        $this->assertEqualsWithDelta(6000, $this->year($result['local_quantities']), 1e-6);
        $this->assertEqualsWithDelta(6000, $this->year($result['export_quantities']), 1e-6);
    }

    public function test_without_an_export_pattern_export_follows_the_local_one(): void
    {
        $local = SeasonalityCalculator::monthWeights('quarterly', [10, 20, 30, 40]);
        $same = ProductSalesCalculator::calculate($this->timeline(), $this->input(['seasonality_weights' => $local]));
        $explicit = ProductSalesCalculator::calculate($this->timeline(), $this->input(['seasonality_weights' => $local, 'export_seasonality_weights' => $local]));

        $this->assertSame($same['export_quantities'], $explicit['export_quantities']);
        $this->assertEqualsWithDelta(6000 * 0.10 / 3, $same['export_quantities'][0], 1e-9);
    }

    public function test_months_before_the_selling_start_have_no_sales_in_either_pattern(): void
    {
        $result = ProductSalesCalculator::calculate($this->timeline(), $this->input([
            'selling_start_index'        => 3,
            'seasonality_weights'        => SeasonalityCalculator::monthWeights('quarterly', [20, 30, 35, 15]),
            'export_seasonality_weights' => SeasonalityCalculator::monthWeights('flat'),
        ]));

        foreach ([0, 1, 2] as $month) {
            $this->assertEqualsWithDelta(0, $result['local_quantities'][$month], 1e-12);
            $this->assertEqualsWithDelta(0, $result['export_quantities'][$month], 1e-12);
            $this->assertEqualsWithDelta(0, $result['segments']['export']['UAE']['values'][$month], 1e-12);
        }
        // The year's quantity is squeezed into the 9 selling months.
        $this->assertEqualsWithDelta(6000, $this->year($result['local_quantities']), 1e-6);
        $this->assertEqualsWithDelta(6000 / 9, $result['export_quantities'][3], 1e-9);
    }

    public function test_the_units_to_make_follow_the_sum_of_local_and_export(): void
    {
        $result = ProductSalesCalculator::calculate($this->timeline(), $this->input([
            'seasonality_weights' => SeasonalityCalculator::monthWeights('quarterly', [20, 30, 35, 15]),
            'fg_coverage_days'    => 30,
        ]));

        // One month of cover: January's units to make = January's sales + February's sales (local + export together).
        $this->assertEqualsWithDelta($result['quantities'][0] + $result['quantities'][1], $result['inventory']['manufacturing_quantity'][0], 1e-9);
    }

    // ── old behaviour kept (R8) ─────────────────────────────

    public function test_one_channel_at_100_percent_and_one_market_give_exactly_the_old_figures(): void
    {
        $old = ProductSalesCalculator::calculate($this->timeline(), [
            'selling_start_index' => 0, 'seasonality_weights' => SeasonalityCalculator::monthWeights('quarterly', [20, 30, 35, 15]),
            'local_quantities' => $this->years(1200), 'local_prices' => $this->years(10), 'export_quantities' => $this->years(600), 'export_prices' => $this->years(20),
            'fg_beginning_quantity' => 50, 'fg_coverage_days' => 45, 'recipes' => [3 => [0 => 12, 1 => 12]],
        ]);
        $new = ProductSalesCalculator::calculate($this->timeline(), [
            'selling_start_index' => 0, 'seasonality_weights' => SeasonalityCalculator::monthWeights('quarterly', [20, 30, 35, 15]),
            'local_quantities' => $this->years(1200), 'local_prices' => $this->years(10), 'export_quantities' => $this->years(600), 'export_prices' => $this->years(20),
            'local_segments' => ['Local' => ['quantities' => SegmentCalculator::quantities($this->years(1200), $this->years(100)), 'prices' => SegmentCalculator::localPrices($this->years(10), $this->years(0))]],
            'export_segments' => ['Export' => ['quantities' => SegmentCalculator::quantities($this->years(600), $this->years(100)), 'prices' => SegmentCalculator::exportPrices($this->years(20), $this->years(1))]],
            'fg_beginning_quantity' => 50, 'fg_coverage_days' => 45, 'recipes' => [3 => [0 => 12, 1 => 12]],
        ]);

        foreach (['values', 'quantities', 'local_values', 'export_values', 'local_quantities', 'export_quantities', 'inventory', 'raw_material_consumed'] as $key) {
            $this->assertEquals($old[$key], $new[$key], $key);
        }
    }

    public function test_a_product_with_no_segments_is_calculated_as_before(): void
    {
        $result = ProductSalesCalculator::calculate($this->timeline(), [
            'selling_start_index' => 0, 'seasonality_weights' => SeasonalityCalculator::monthWeights('flat'),
            'local_quantities' => $this->years(1200), 'local_prices' => $this->years(10), 'export_quantities' => $this->years(600), 'export_prices' => $this->years(20),
            'fg_beginning_quantity' => 0, 'fg_coverage_days' => 0,
        ]);

        $this->assertEqualsWithDelta(24000, $this->year($result['values']), 1e-6);
        $this->assertEqualsWithDelta((1200 * 10 + 600 * 20) / 1800, $result['average_prices'][0], 1e-9);
    }

    // ── edge cases ──────────────────────────────────────────

    public function test_a_year_with_no_export_gives_zero_export_and_the_average_is_local_only(): void
    {
        $result = ProductSalesCalculator::calculate($this->timeline(), $this->input([
            'export_segments' => ['Saudi Arabia' => ['quantities' => [0 => 0, 1 => 0], 'prices' => [0 => 11500, 1 => 11500]]],
            'export_quantities' => [0 => 0, 1 => 0],
        ]));

        $this->assertEqualsWithDelta(0, $this->year($result['export_values']), 1e-9);
        $this->assertEqualsWithDelta(62400000 / 6000, $result['average_prices'][0], 1e-9);
    }

    public function test_a_changing_fx_rate_moves_the_export_value_year_by_year(): void
    {
        // 1 USD = 50 EGP in the first year and 54 in the second: the same 230 USD price is worth more in the second year.
        $prices = SegmentCalculator::exportPrices([0 => 230, 1 => 230], [0 => 50, 1 => 54]);
        $result = ProductSalesCalculator::calculate($this->timeline(), $this->input([
            'export_segments' => ['USA' => ['quantities' => [0 => 1200, 1 => 1200], 'prices' => $prices]],
        ]));

        $this->assertEqualsWithDelta(1200 * 230 * 50, $this->year($result['export_values'], 0), 1e-6);
        $this->assertEqualsWithDelta(1200 * 230 * 54, $this->year($result['export_values'], 1), 1e-6);
    }

    public function test_the_foreign_price_chain_grows_from_the_year_before_and_rounds_to_two_decimals(): void
    {
        // 10.00, then +3.333% each year; every year is rounded to 2 decimals before the next one grows from it.
        $chain = SegmentCalculator::foreignPrices(10.00, [1 => 3.333, 2 => 3.333, 3 => 3.333], [0, 1, 2, 3]);

        $this->assertSame([0 => 10.00, 1 => 10.33, 2 => 10.67, 3 => 11.03], $chain);
    }

    public function test_the_foreign_price_chain_starts_at_the_first_selling_year(): void
    {
        // A product that starts selling in the second calendar year: year index 1 carries the typed price.
        $chain = SegmentCalculator::foreignPrices(100.0, [1 => 50, 2 => 10], [1, 2]);

        $this->assertSame([1 => 100.0, 2 => 110.0], $chain);
    }

    public function test_channel_prices_are_not_rounded_to_whole_numbers(): void
    {
        $prices = SegmentCalculator::localPrices([0 => 10001], [0 => 3.3]);

        $this->assertEqualsWithDelta(10001 * 1.033, $prices[0], 1e-9);
        $this->assertTrue(abs($prices[0] - round($prices[0])) > 0.01);
    }

    public function test_a_channel_with_no_difference_typed_uses_the_base_price(): void
    {
        $this->assertEqualsWithDelta(10000, SegmentCalculator::localPrices([0 => 10000], [])[0], 1e-9);
    }

    public function test_the_extra_year_repeats_the_last_one(): void
    {
        $this->assertSame([0 => 5, 1 => 7, 2 => 7], SegmentCalculator::extend([0 => 5, 1 => 7]));
        $this->assertSame([], SegmentCalculator::extend([]));
    }

    public function test_the_price_what_if_scales_foreign_prices_too(): void
    {
        $normal = ProductSalesCalculator::calculate($this->timeline(), $this->input());
        $higher = ProductSalesCalculator::calculate($this->timeline(), $this->input(['sensitivity_price_rate' => 10]), true);

        $this->assertEqualsWithDelta(1.10 * $this->year($normal['export_values']), $this->year($higher['export_values']), 0.01);
        $this->assertEqualsWithDelta(1.10 * $this->year($normal['local_values']), $this->year($higher['local_values']), 0.01);
        $this->assertEqualsWithDelta($this->year($normal['quantities']), $this->year($higher['quantities']), 1e-9);
        $this->assertEqualsWithDelta(1.10 * 10770, $higher['average_prices'][0], 1e-6);
    }

    public function test_the_fx_what_if_is_the_same_as_scaled_rates(): void
    {
        // FX +10%: every foreign rate x 1.10 (the what-if scales the saved rates and runs the whole calculation again).
        $base = ProductSalesCalculator::calculate($this->timeline(), $this->input());
        $moved = ProductSalesCalculator::calculate($this->timeline(), $this->input(['export_segments' => $this->markets(55, 60.5)]));

        $this->assertEqualsWithDelta(1.10 * $this->year($base['export_values']), $this->year($moved['export_values']), 0.01);
        $this->assertEqualsWithDelta($this->year($base['local_values']), $this->year($moved['local_values']), 1e-6);
    }

    public function test_the_sales_target_what_if_scales_the_group_and_keeps_the_shares(): void
    {
        $scaled = array_map(fn ($q) => $q * 0.9, [0 => 6000, 1 => 6000]);
        $segment = SegmentCalculator::quantities($scaled, [0 => 50, 1 => 50]);

        $this->assertEqualsWithDelta(2700, $segment[0], 1e-9);   // 10% fewer units, still 50% of the group
    }

    // ── collections ─────────────────────────────────────────

    public function test_each_channel_is_collected_on_its_own_terms_and_the_statements_are_added(): void
    {
        $timeline = $this->timeline();
        $result = ProductSalesCalculator::calculate($timeline, $this->input());
        $cash = ['cash_payment' => 100, 'rate' => [0, 0], 'due_in_days' => [30, 60]];
        $after30 = ['cash_payment' => 0, 'rate' => [100, 0], 'due_in_days' => [30, 60]];
        $after60 = ['cash_payment' => 0, 'rate' => [100, 0], 'due_in_days' => [60, 90]];

        $retail = CollectionPolicy::statement($result['segments']['local']['Retail']['values'], 0, 0, $cash, $cash, $timeline);
        $wholesale = CollectionPolicy::statement($result['segments']['local']['Wholesale']['values'], 0, 0, $after30, $after30, $timeline);
        $distributors = CollectionPolicy::statement($result['segments']['local']['Distributors']['values'], 0, 0, $after60, $after60, $timeline);
        $total = CollectionPolicy::addStatements(CollectionPolicy::addStatements($retail, $wholesale), $distributors);

        $r = $result['segments']['local']['Retail']['values'];
        $w = $result['segments']['local']['Wholesale']['values'];
        $d = $result['segments']['local']['Distributors']['values'];
        $paid = $total['monthly']['payment'];

        // Retail pays in the month, Wholesale a month later, Distributors two months later.
        $this->assertEqualsWithDelta($r[0], $paid[0], 1e-6);
        $this->assertEqualsWithDelta($r[1] + $w[0], $paid[1], 1e-6);
        $this->assertEqualsWithDelta($r[2] + $w[1] + $d[0], $paid[2], 1e-6);

        // Sales are the sum of the three; what is still owed at the end of January is Wholesale's and Distributors' January sales.
        $this->assertEqualsWithDelta($r[0] + $w[0] + $d[0], $total['monthly']['expense'][0], 1e-6);
        $this->assertEqualsWithDelta($w[0] + $d[0], $total['monthly']['end_balance'][0], 1e-6);
    }

    public function test_a_market_can_pay_half_in_advance_and_half_after_sixty_days(): void
    {
        $timeline = $this->timeline();
        $result = ProductSalesCalculator::calculate($timeline, $this->input());
        $terms = ['cash_payment' => 50, 'rate' => [50, 0], 'due_in_days' => [60, 90]];
        $italy = $result['segments']['export']['Italy']['values'];

        $statement = CollectionPolicy::statement($italy, 0, 0, $terms, $terms, $timeline);

        $this->assertEqualsWithDelta(0.5 * $italy[0], $statement['monthly']['payment'][0], 1e-6);
        $this->assertEqualsWithDelta(0.5 * $italy[2] + 0.5 * $italy[0], $statement['monthly']['payment'][2], 1e-6);
    }

    public function test_collection_what_if_adds_days_to_the_own_terms_of_a_segment_too(): void
    {
        $terms = [
            0 => ['cash_payment' => 20, 'rate' => [50, 30], 'due_in_days' => [30, 60]],
            1 => ['cash_payment' => 100, 'rate' => [0, 0], 'due_in_days' => [30, 60]],
        ];

        $later = SensitivityService::delayPeriods($terms, 30);
        $this->assertSame([60, 90], $later[0]['due_in_days']);
        $this->assertSame([60, 90], $later[1]['due_in_days']);

        $earlier = SensitivityService::delayPeriods($terms, -45);   // never below zero
        $this->assertSame([0, 15], $earlier[0]['due_in_days']);

        // The product's own local / export terms are delayed by the same rule.
        $policy = SensitivityService::delay(['local' => $terms, 'export' => $terms], 30);
        $this->assertSame([60, 90], $policy['local'][0]['due_in_days']);
        $this->assertSame([60, 90], $policy['export'][1]['due_in_days']);
    }

    // ── size ────────────────────────────────────────────────

    public function test_the_largest_study_8_channels_10_markets_7_years_is_calculated_quickly(): void
    {
        $timeline = Timeline::forStudy('2026-01-01', 7, '2026-01-01');
        $years = range(0, 7);
        $each = fn ($v) => array_fill_keys($years, $v);

        $local = $export = [];
        foreach (range(1, 8) as $i) {
            $local["c$i"] = ['quantities' => $each(1000 / 8), 'prices' => $each(100 + $i)];
        }
        foreach (range(1, 10) as $i) {
            $export["m$i"] = ['quantities' => $each(1000 / 10), 'prices' => $each(2 * (100 + $i))];
        }

        $started = microtime(true);
        $result = ProductSalesCalculator::calculate($timeline, [
            'selling_start_index' => 0, 'seasonality_weights' => SeasonalityCalculator::monthWeights('monthly', [5, 5, 5, 8, 8, 8, 10, 10, 10, 12, 12, 7]),
            'export_seasonality_weights' => SeasonalityCalculator::monthWeights('flat'),
            'local_quantities' => $each(1000), 'local_prices' => $each(0), 'export_quantities' => $each(1000), 'export_prices' => $each(0),
            'local_segments' => $local, 'export_segments' => $export, 'fg_beginning_quantity' => 0, 'fg_coverage_days' => 30, 'recipes' => [1 => $each(10), 2 => $each(5)],
        ]);
        $seconds = microtime(true) - $started;

        $this->assertCount(8, $result['segments']['local']);
        $this->assertCount(10, $result['segments']['export']);
        $this->assertEqualsWithDelta(2000, $this->year($result['quantities'], 0), 1e-6);
        $this->assertLessThan(1.0, $seconds, "the engine took $seconds s");
    }
}
