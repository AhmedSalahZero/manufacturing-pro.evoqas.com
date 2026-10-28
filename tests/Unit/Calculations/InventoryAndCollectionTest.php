<?php

namespace Tests\Unit\Calculations;

use App\Calculations\CollectionPolicy;
use App\Calculations\InventoryQuantityStatement;
use App\Calculations\IntervalSums;
use App\Calculations\Timeline;
use PHPUnit\Framework\TestCase;

// How much to make (or buy) each month, and when the money arrives. Every expected figure below was worked out by hand.
// Run:  php artisan test --filter=InventoryAndCollectionTest
class InventoryAndCollectionTest extends TestCase
{
    /** Same keys, and every figure equal to within a billionth (decimal fractions are never exact in a computer). */
    private function assertSeriesAlmost(array $expected, array $actual): void
    {
        $this->assertSame(array_keys($expected), array_keys($actual));

        foreach ($expected as $month => $value) {
            $this->assertEqualsWithDelta($value, $actual[$month], 1e-9, "month $month");
        }
    }

    public function test_inventory_statement_keeps_the_coverage_months_in_stock(): void
    {
        // Selling 100 a month, 150 in stock to begin with, and 1.5 months of future sales to keep.
        $statement = InventoryQuantityStatement::build([0 => 100, 1 => 100, 2 => 100, 3 => 100], 150, 1.5);

        $this->assertEquals([0 => 150, 1 => 150, 2 => 150, 3 => 100], $statement['beginning_balance']);
        $this->assertEquals([0 => 100, 1 => 100, 2 => 50, 3 => 0], $statement['manufacturing_quantity']);
        $this->assertEquals([0 => 150, 1 => 150, 2 => 100, 3 => 0], $statement['end_balance']);
        $this->assertEquals([0 => 100, 1 => 100, 2 => 100, 3 => 100], $statement['sales_quantity']);
    }

    public function test_with_no_coverage_and_no_stock_everything_sold_is_made_the_same_month(): void
    {
        $statement = InventoryQuantityStatement::build([5 => 30, 6 => 40], 0, 0);

        $this->assertEquals([5 => 30, 6 => 40], $statement['manufacturing_quantity']);
        $this->assertEquals([5 => 0, 6 => 0], $statement['end_balance']);
    }

    public function test_due_days_become_months(): void
    {
        $this->assertSame(0, CollectionPolicy::monthsLater(15));
        $this->assertSame(1, CollectionPolicy::monthsLater(30));
        $this->assertSame(2, CollectionPolicy::monthsLater(45));   // 1.5 rounds up
        $this->assertSame(2, CollectionPolicy::monthsLater(60));
        $this->assertSame(3, CollectionPolicy::monthsLater(75));   // 2.5 rounds up
        $this->assertSame(6, CollectionPolicy::monthsLater(180));
    }

    public function test_equal_due_days_are_added_not_overwritten(): void
    {
        $map = CollectionPolicy::dueDayRates(['cash_payment' => '50', 'rate' => ['25', '25'], 'due_in_days' => ['30', '30']]);

        $this->assertEquals([0 => 50.0, 30 => 50.0], $map);
    }

    public function test_a_collection_statement_follows_the_terms(): void
    {
        $timeline = Timeline::forStudy('2026-01-01', 1, '2026-01-01');
        $terms = ['cash_payment' => 20, 'rate' => [50, 30], 'due_in_days' => [30, 60]];

        $statement = CollectionPolicy::statement([0 => 1000, 1 => 2000, 2 => 0], 10, 5, $terms, $terms, $timeline)['monthly'];

        $this->assertSeriesAlmost([0 => 100, 1 => 200, 2 => 0], $statement['vat']);
        $this->assertSeriesAlmost([0 => 210, 1 => 945, 2 => 1365], $statement['payment']);
        $this->assertSeriesAlmost([0 => 10, 1 => 45, 2 => 65], $statement['withhold_amount']);
        $this->assertSeriesAlmost([0 => 1100, 1 => 3080, 2 => 2090], $statement['total_due']);
        $this->assertSeriesAlmost([0 => 880, 1 => 2090, 2 => 660], $statement['end_balance']);
    }

    public function test_the_later_years_use_their_own_terms(): void
    {
        $timeline = Timeline::forStudy('2026-01-01', 2, '2026-01-01');
        $cash = ['cash_payment' => 100, 'rate' => [0, 0], 'due_in_days' => [30, 60]];
        $slow = ['cash_payment' => 0, 'rate' => [100, 0], 'due_in_days' => [30, 60]];

        // A sale in month 11 (the last month of the first year) is cash; the same sale in month 12 is paid a month later.
        $statement = CollectionPolicy::statement([11 => 100, 12 => 100, 13 => 0], 0, 0, $cash, $slow, $timeline)['monthly'];

        $this->assertSeriesAlmost([11 => 100, 12 => 0, 13 => 100], $statement['payment']);
        $this->assertSeriesAlmost([11 => 0, 12 => 100, 13 => 0], $statement['end_balance']);
    }

    public function test_money_arriving_after_the_last_month_is_not_counted(): void
    {
        $timeline = Timeline::forStudy('2026-01-01', 1, '2026-01-01');
        $slow = ['cash_payment' => 0, 'rate' => [100, 0], 'due_in_days' => [30, 60]];

        $statement = CollectionPolicy::statement([0 => 100, 1 => 100], 0, 0, $slow, $slow, $timeline)['monthly'];

        $this->assertSeriesAlmost([0 => 0, 1 => 100], $statement['payment']);
        $this->assertSeriesAlmost([0 => 100, 1 => 100], $statement['end_balance']);
    }

    public function test_two_statements_add_up_column_by_column(): void
    {
        $timeline = Timeline::forStudy('2026-01-01', 1, '2026-01-01');
        $cash = ['cash_payment' => 100, 'rate' => [0, 0], 'due_in_days' => [30, 60]];
        $a = CollectionPolicy::statement([0 => 100], 0, 0, $cash, $cash, $timeline);
        $b = CollectionPolicy::statement([0 => 50], 0, 0, $cash, $cash, $timeline);

        $this->assertEquals([0 => 150], CollectionPolicy::addStatements($a, $b)['monthly']['expense']);
    }

    public function test_quarterly_and_yearly_totals_sit_at_the_last_month_of_the_period(): void
    {
        $timeline = Timeline::forStudy('2026-01-01', 1, '2026-01-01');
        $series = array_fill(0, 12, 10);

        $quarterly = IntervalSums::sum($series, 'quarterly', $timeline);
        $this->assertEquals([2 => 30, 5 => 30, 8 => 30, 11 => 30], $quarterly);
        $this->assertEquals([5 => 60, 11 => 60], IntervalSums::sum($series, 'semi-annually', $timeline));
        $this->assertEquals([11 => 120], IntervalSums::sum($series, 'annually', $timeline));
    }

    public function test_quarterly_statements_add_up_the_vat_and_withholding_of_the_whole_quarter(): void
    {
        // The previous ZAVERO used only the last month's VAT and withholding in its quarterly / yearly statements.
        // That was a slip (checked against its saved results); here the whole period is added.
        $timeline = Timeline::forStudy('2026-01-01', 1, '2026-01-01');
        $cash = ['cash_payment' => 100, 'rate' => [0, 0], 'due_in_days' => [30, 60]];

        $statement = CollectionPolicy::statement([0 => 100, 1 => 100, 2 => 100], 10, 0, $cash, $cash, $timeline)['quarterly'];

        $this->assertEquals([2 => 300], $statement['expense']);
        $this->assertSeriesAlmost([2 => 30], $statement['vat']);
        $this->assertEquals([2 => 0], $statement['end_balance']);
    }
}
