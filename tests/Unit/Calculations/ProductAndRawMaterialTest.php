<?php

namespace Tests\Unit\Calculations;

use App\Calculations\ProductSalesCalculator;
use App\Calculations\RawMaterialCalculator;
use App\Calculations\SeasonalityCalculator;
use App\Calculations\Timeline;
use PHPUnit\Framework\TestCase;

// Product sales, units to make, raw materials consumed, purchases and supplier payments. Figures worked out by hand.
// Run:  php artisan test --filter=ProductAndRawMaterialTest
class ProductAndRawMaterialTest extends TestCase
{
    /** Same keys, and every figure equal to within a billionth (decimal fractions are never exact in a computer). */
    private function assertSeriesAlmost(array $expected, array $actual): void
    {
        foreach ($expected as $month => $value) {
            $this->assertEqualsWithDelta($value, $actual[$month] ?? 0, 1e-9, "month $month");
        }
    }

    private function timeline(): Timeline
    {
        return Timeline::forStudy('2026-01-01', 1, '2026-01-01');   // 24 months
    }

    /** Local 1200 units at 10, export 600 units at 20 in the study year (and the same in the extended year). */
    private function input(array $override = []): array
    {
        return array_merge([
            'selling_start_index'   => 0,
            'seasonality_weights'   => SeasonalityCalculator::monthWeights('flat'),
            'local_quantities'      => [0 => 1200, 1 => 1200],
            'local_prices'          => [0 => 10, 1 => 10],
            'export_quantities'     => [0 => 600, 1 => 600],
            'export_prices'         => [0 => 20, 1 => 20],
            'fg_beginning_quantity' => 0,
            'fg_coverage_days'      => 0,
            'recipes'               => [],
        ], $override);
    }

    public function test_the_yearly_quantity_is_spread_over_the_months(): void
    {
        $result = ProductSalesCalculator::calculate($this->timeline(), $this->input());

        $this->assertEqualsWithDelta(100, $result['local_quantities'][0], 1e-9);
        $this->assertEqualsWithDelta(1000, $result['local_values'][0], 1e-9);
        $this->assertEqualsWithDelta(50, $result['export_quantities'][5], 1e-9);
        $this->assertEqualsWithDelta(1000, $result['export_values'][5], 1e-9);
        $this->assertEqualsWithDelta(150, $result['quantities'][11], 1e-9);
        $this->assertEqualsWithDelta(2000, $result['values'][23], 1e-9);
        $this->assertEqualsWithDelta(24000, array_sum(array_slice($result['values'], 0, 12)), 1e-6);
    }

    public function test_a_later_selling_start_squeezes_the_year_into_the_remaining_months(): void
    {
        $result = ProductSalesCalculator::calculate($this->timeline(), $this->input(['selling_start_index' => 3]));

        $this->assertEqualsWithDelta(0, $result['values'][2], 1e-9);
        $this->assertEqualsWithDelta(24000, array_sum(array_slice($result['values'], 0, 12)), 1e-6);   // the year still sells its full target
        $this->assertEqualsWithDelta(24000 / 9, $result['values'][3], 1e-6);
    }

    public function test_units_to_make_follow_sales_stock_and_coverage(): void
    {
        $result = ProductSalesCalculator::calculate($this->timeline(), $this->input(['fg_beginning_quantity' => 300, 'fg_coverage_days' => 30]));

        // Month 0: sell 150, keep 150 for next month: need 300, already have 300 → make nothing.
        $this->assertEqualsWithDelta(0, $result['inventory']['manufacturing_quantity'][0], 1e-9);
        // Month 1: opening 150, need 300 → make 150, then 150 every month.
        $this->assertEqualsWithDelta(150, $result['inventory']['manufacturing_quantity'][1], 1e-9);
        $this->assertEqualsWithDelta(150, $result['inventory']['end_balance'][1], 1e-9);
    }

    public function test_raw_materials_are_consumed_in_proportion_to_what_is_made(): void
    {
        $result = ProductSalesCalculator::calculate($this->timeline(), $this->input([
            'recipes' => [7 => [0 => 30, 1 => 40], 8 => [0 => 10, 1 => 10]],
        ]));

        // 30 % of the 2000 a month, as 150 units are made and 150 sold: 600; year two 40 %: 800.
        $this->assertEqualsWithDelta(600, $result['raw_material_consumed'][7][0], 1e-6);
        $this->assertEqualsWithDelta(800, $result['raw_material_consumed'][7][12], 1e-6);
        $this->assertEqualsWithDelta(200, $result['raw_material_consumed'][8][3], 1e-6);
        $this->assertEqualsWithDelta(800, $result['raw_material_consumed']['total'][3], 1e-6);
    }

    public function test_the_price_sensitivity_changes_the_values_but_not_the_quantities(): void
    {
        $normal = ProductSalesCalculator::calculate($this->timeline(), $this->input());
        $higher = ProductSalesCalculator::calculate($this->timeline(), $this->input(['sensitivity_price_rate' => 10]), true);

        $this->assertEqualsWithDelta($normal['values'][0] * 1.1, $higher['values'][0], 1e-6);
        $this->assertEqualsWithDelta($normal['quantities'][0], $higher['quantities'][0], 1e-9);
    }

    public function test_the_yearly_quantities_come_from_the_capacity(): void
    {
        $q = ProductSalesCalculator::yearlyQuantities([0 => 6000, 1 => 6600], [0 => 50, 1 => 50], [0 => 80, 1 => 80]);

        $this->assertEquals([0 => 3000.0, 1 => 3300.0], $q['target']);
        $this->assertEquals([0 => 2400.0, 1 => 2640.0], $q['local']);
        $this->assertEquals([0 => 600.0, 1 => 660.0], $q['export']);
    }

    public function test_the_price_chain_grows_from_the_rounded_year_before(): void
    {
        $prices = ProductSalesCalculator::priceChain(60000, array_fill(0, 7, 10), 7);

        $this->assertEquals([60000, 66000, 72600, 79860, 87846, 96631, 106294], array_values($prices));
    }

    public function test_raw_material_purchases_and_supplier_balance(): void
    {
        $cash = ['cash_payment' => 100, 'rate' => [0, 0], 'due_in_days' => [30, 60]];

        // 100 consumed a month, one month of stock kept, nothing in stock to begin with: buy 200, then 100, 100, 0.
        $result = RawMaterialCalculator::calculate($this->timeline(), [0 => 100, 1 => 100, 2 => 100, 3 => 100], 0, 30, 14, 0, $cash, $cash);

        $this->assertEquals([0 => 200, 1 => 100, 2 => 100, 3 => 0], $result['inventory']['manufacturing_quantity']);
        $this->assertSeriesAlmost([0 => 28, 1 => 14, 2 => 14, 3 => 0], $result['payments']['monthly']['vat']);
        $this->assertSeriesAlmost([0 => 0, 1 => 0, 2 => 0, 3 => 0], $result['payments']['monthly']['end_balance']);
    }

    public function test_a_supplier_paid_a_month_later_leaves_a_balance(): void
    {
        $later = ['cash_payment' => 0, 'rate' => [100, 0], 'due_in_days' => [30, 60]];

        $result = RawMaterialCalculator::calculate($this->timeline(), [0 => 100, 1 => 100, 2 => 100], 0, 0, 0, 0, $later, $later);

        $this->assertEquals([0 => 100, 1 => 100, 2 => 100], $result['payments']['monthly']['expense']);
        $this->assertEquals([0 => 0, 1 => 100, 2 => 100], $result['payments']['monthly']['payment']);
        $this->assertEquals([0 => 100, 1 => 100, 2 => 100], $result['payments']['monthly']['end_balance']);
    }

    public function test_withholding_tax_credit_is_settled_in_the_filing_months(): void
    {
        $result = RawMaterialCalculator::withholdStatement([2 => 10, 3 => 20, 4 => 5], 0, $this->timeline())['monthly'];

        // Month 3 is April: the balance of March (10) is settled.
        $this->assertSeriesAlmost([2 => 0, 3 => 10, 4 => 20], $result['beginning_balance']);
        $this->assertSeriesAlmost([2 => 0, 3 => 10, 4 => 0], $result['payment']);
        $this->assertSeriesAlmost([2 => 10, 3 => 20, 4 => 25], $result['end_balance']);
    }

    public function test_raw_material_is_used_for_units_made_before_the_first_sale_at_the_years_price(): void
    {
        // Sales start in month 2 (March). 75 days of stock are made ahead, so units are made in January and February too.
        $result = \App\Calculations\ProductSalesCalculator::calculate(
            \App\Calculations\Timeline::forStudy('2026-01-01', 1, '2026-01-01'),
            [
                'selling_start_index' => 2, 'seasonality_weights' => \App\Calculations\SeasonalityCalculator::monthWeights('flat', []),
                'local_quantities' => [0 => 1000], 'local_prices' => [0 => 50], 'export_quantities' => [0 => 200], 'export_prices' => [0 => 80],
                'fg_beginning_quantity' => 0, 'fg_coverage_days' => 75, 'recipes' => [7 => [0 => 40]],
            ],
        );

        $made = $result['inventory']['manufacturing_quantity'];
        $this->assertEquals(true, $made[0] > 0);
        $this->assertEquals(0, $result['quantities'][0]);

        // Average price = (1000 x 50 + 200 x 80) / 1200 = 55; the material is 40 % of it.
        $this->assertEqualsWithDelta(0.40 * 55 * $made[0], $result['raw_material_consumed'][7][0], 1e-9);

        // In a month with sales it is the same as month sales value / units sold, as before.
        $this->assertEqualsWithDelta(0.40 * $result['values'][5] / $result['quantities'][5] * $made[5], $result['raw_material_consumed'][7][5], 1e-9);
    }
}
