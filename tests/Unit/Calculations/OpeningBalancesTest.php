<?php

namespace Tests\Unit\Calculations;

use App\Calculations\OpeningBalanceCalculator;
use App\Calculations\Timeline;
use PHPUnit\Framework\TestCase;

// Opening balances of an existing company: the monthly settlement statements, owned fixed assets, and the balance check.
// Part 1 is worked by hand. Part 2 compares the statements with what the previous ZAVERO's own calculation gives for the
// same inputs (tests/Fixtures/stage5_golden.json: 12 cases over 3 start months and the 4 views, every value within 0.000001).
// Run:  php artisan test --filter=OpeningBalancesTest
class OpeningBalancesTest extends TestCase
{
    private function timeline(string $start = '2026-01-01', int $years = 3, ?string $operation = null): Timeline
    {
        return Timeline::forStudy($start, $years, $operation ?? $start);
    }

    public function test_a_balance_settled_in_two_parts_runs_down_to_zero(): void
    {
        $t = $this->timeline();
        $s = OpeningBalanceCalculator::settlementStatement(range(0, 35), [1 => 60000, 3 => 40000], 100000.0, $t)['monthly'];

        $this->assertEqualsWithDelta(100000, $s['beginning_balance'][0], 0.001);
        $this->assertEqualsWithDelta(100000, $s['end_balance'][0], 0.001);
        $this->assertEqualsWithDelta(40000, $s['end_balance'][1], 0.001);
        $this->assertEqualsWithDelta(40000, $s['end_balance'][2], 0.001);
        $this->assertEqualsWithDelta(0, $s['end_balance'][3], 0.001);
        $this->assertEqualsWithDelta(0, $s['end_balance'][35], 0.001);
        $this->assertEqualsWithDelta(60000, $s['payment'][1], 0.001);
    }

    public function test_the_quarterly_view_adds_settlements_at_the_end_of_the_quarter(): void
    {
        $t = $this->timeline();
        // January 20 000, February 30 000: both fall in the first quarter, which ends in March (month 2).
        $q = OpeningBalanceCalculator::settlementStatement(range(0, 35), [0 => 20000, 1 => 30000], 100000.0, $t)['quarterly'];

        $this->assertEqualsWithDelta(0, $q['payment'][0], 0.001);
        $this->assertEqualsWithDelta(0, $q['payment'][1], 0.001);
        $this->assertEqualsWithDelta(50000, $q['payment'][2], 0.001);
        $this->assertEqualsWithDelta(100000, $q['end_balance'][1], 0.001);
        $this->assertEqualsWithDelta(50000, $q['end_balance'][2], 0.001);
    }

    public function test_a_balance_with_no_settlements_stays_on_the_books(): void
    {
        $s = OpeningBalanceCalculator::settlementStatement(range(0, 11), [], 5000.0, $this->timeline(years: 1))['annually'];

        $this->assertEqualsWithDelta(5000, $s['end_balance'][11], 0.001);
    }

    public function test_an_owned_asset_depreciates_for_the_months_it_has_left(): void
    {
        $t = $this->timeline();
        $r = OpeningBalanceCalculator::fixedAsset(['gross' => 120000.0, 'accumulated' => 30000.0, 'monthly' => 1000.0, 'months' => 24, 'admin_pct' => 25.0, 'manufacturing_pct' => 75.0], $t, range(0, 35));

        $this->assertEqualsWithDelta(250, $r['admin'][0], 0.001);
        $this->assertEqualsWithDelta(750, $r['manufacturing'][0], 0.001);
        $this->assertArrayHasKey(23, $r['admin']);
        $this->assertArrayNotHasKey(24, $r['admin']);
        // Net value: 120 000 - 30 000 = 90 000 before the first month, then 1 000 less each month.
        $m = $r['statement']['monthly']['end_balance'];
        $this->assertEqualsWithDelta(89000, $m[0], 0.001);
        $this->assertEqualsWithDelta(66000, $m[23], 0.001);
        $this->assertEqualsWithDelta(66000, $m[35], 0.001);   // fully used up its remaining months
    }

    public function test_depreciation_starts_at_the_operation_start_not_the_study_start(): void
    {
        // The study starts in January; operation starts in April (month 3). The old application began three months late here.
        $t = $this->timeline('2026-01-01', 3, '2026-04-01');
        $r = OpeningBalanceCalculator::fixedAsset(['gross' => 10000.0, 'accumulated' => 0.0, 'monthly' => 100.0, 'months' => 12, 'admin_pct' => 0.0, 'manufacturing_pct' => 100.0], $t, range(0, 35));

        $this->assertArrayNotHasKey(2, $r['manufacturing']);
        $this->assertEqualsWithDelta(100, $r['manufacturing'][3], 0.001);
        $this->assertArrayHasKey(14, $r['manufacturing']);
        $this->assertArrayNotHasKey(15, $r['manufacturing']);
    }

    public function test_balance_check(): void
    {
        $totals = ['net_fixed_assets' => 90000.0, 'cash' => 10000.0, 'receivables' => 5000.0, 'inventory' => 5000.0, 'other_debtors' => 0.0,
            'suppliers' => 20000.0, 'other_creditors' => 0.0, 'vat' => 0.0, 'withhold' => 0.0, 'corporate_tax' => 0.0,
            'loans' => 30000.0, 'other_long_term' => 0.0, 'paid_up' => 50000.0, 'legal_reserve' => 5000.0, 'retained_earnings' => 5000.0];
        $ok = OpeningBalanceCalculator::balance($totals);
        $this->assertTrue($ok['balanced']);
        $this->assertEqualsWithDelta(110000, $ok['assets'], 0.001);

        $totals['corporate_tax'] = 2500.0;   // the old check forgot this liability
        $bad = OpeningBalanceCalculator::balance($totals);
        $this->assertFalse($bad['balanced']);
        $this->assertEqualsWithDelta(2500, $bad['difference'], 0.001);
    }

    public function test_a_repeated_balance_covers_every_month(): void
    {
        $r = OpeningBalanceCalculator::repeated(1000.0, [0, 1, 2]);
        $this->assertSame([0 => 1000.0, 1 => 1000.0, 2 => 1000.0], $r);
    }

    // ── Part 2: the previous application's own results ──

    public function test_the_statements_match_the_previous_application(): void
    {
        $golden = json_decode(file_get_contents(__DIR__.'/../../Fixtures/stage5_golden.json'), true);
        $this->assertCount(12, $golden['cases']);

        foreach ($golden['cases'] as $n => $case) {
            $t = Timeline::forStudy($case['start'], $case['years'], $case['operation']);
            $months = range(0, $case['years'] * 12 - 1);
            $settlements = [];
            foreach ($case['settlements'] as $month => $amount) {
                $settlements[(int) $month] = $amount;
            }
            $new = OpeningBalanceCalculator::settlementStatement($months, $settlements, (float) $case['opening'], $t);

            foreach ($case['expected'] as $interval => $columns) {
                foreach ($columns as $column => $values) {
                    foreach ($values as $position => $expected) {
                        $this->assertEqualsWithDelta($expected, $new[$interval][$column][$months[$position]], 0.000001, "case $n $interval $column month $position");
                    }
                }
            }
        }
    }
}
