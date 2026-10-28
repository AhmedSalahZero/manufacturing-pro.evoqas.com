<?php

namespace Tests\Unit\Calculations;

use App\Calculations\DashboardCalculator as D;
use PHPUnit\Framework\TestCase;

// The numbers of the Dashboard (growth, margins, free cash flow, WACC, NPV, IRR, payback, liquidity and activity ratios), worked by hand.
// Run:  php artisan test --filter=DashboardTest
class DashboardTest extends TestCase
{
    private const DELTA = 0.01;

    private function two(float $perMonth0, float $perMonth1): array
    {
        $series = [];
        foreach (range(0, 11) as $m) {
            $series[$m] = $perMonth0;
        }
        foreach (range(12, 23) as $m) {
            $series[$m] = $perMonth1;
        }

        return $series;
    }

    private function input(array $override = []): array
    {
        return $override + [
            'years'                      => [0 => range(0, 11), 1 => range(12, 23)],
            'revenue'                    => $this->two(100, 110),
            'gross_profit'               => $this->two(40, 44),
            'ebitda'                     => $this->two(14, 14),
            'ebit'                       => $this->two(10, 10),
            'net_profit'                 => $this->two(8, 8),
            'cogs_lines'                 => ['raw_material' => $this->two(30, 33), 'direct_labor' => $this->two(20, 22), 'manufacturing_overheads' => $this->two(10, 11)],
            'expense_lines'              => ['marketing' => $this->two(5, 5), 'sales' => $this->two(5, 5), 'general-expenses' => $this->two(20, 20)],
            'depreciation'               => $this->two(2, 2),
            'net_change_working_capital' => [0 => -50, 1 => 20],
            'capex'                      => [0 => 300] + array_fill(1, 23, 0),
            'tax_rate'                   => 20.0,
            'perpetual_growth_rate'      => 2.0,
            'return_rate'                => 10.0,
            'cost_of_debt'               => 0.0,
            'equity_funding'             => [0 => 100, 1 => 100],
            'debt_funding'               => [0 => 0, 1 => 0],
        ];
    }

    public function test_yearly_revenue_growth_and_margins(): void
    {
        $r = D::results($this->input());

        $this->assertEqualsWithDelta(1200, $r['revenue'][0], self::DELTA);
        $this->assertEqualsWithDelta(1320, $r['revenue'][1], self::DELTA);
        $this->assertEqualsWithDelta(0, $r['growth'][0], self::DELTA, 'no growth in the first year');
        $this->assertEqualsWithDelta(10, $r['growth'][1], self::DELTA);
        $this->assertEqualsWithDelta(2520, $r['accumulated'][1], self::DELTA);
        $this->assertEqualsWithDelta(40, $r['profit']['gross_profit']['percent'][0], self::DELTA);
        $this->assertEqualsWithDelta(8 * 12 / 1320 * 100, $r['profit']['net_profit']['percent'][1], self::DELTA);
        $this->assertEqualsWithDelta(30, $r['costs']['raw_material']['percent'][0], self::DELTA);
        $this->assertEqualsWithDelta(240 / 1200 * 100, $r['costs']['general']['percent'][0], self::DELTA);
    }

    public function test_free_cash_flow_terminal_value_npv_and_irr(): void
    {
        $dcf = D::results($this->input())['dcf'];

        // EBIT 120 + depreciation 24 − working capital 50 − tax 24 − capex 300 = −230;  second year 120 + 24 + 20 − 24 = 140
        $this->assertEqualsWithDelta(24, $dcf['taxes'][0], self::DELTA);
        $this->assertEqualsWithDelta(-230, $dcf['free_cash_flow'][0], self::DELTA);
        $this->assertEqualsWithDelta(140, $dcf['free_cash_flow'][1], self::DELTA);
        $this->assertEqualsWithDelta(0.10, $dcf['wacc'][1], 0.000001);
        // 140 × 1.02 ÷ (10 % − 2 %) = 1785
        $this->assertEqualsWithDelta(1785, $dcf['terminal_value'][1], self::DELTA);
        $this->assertEqualsWithDelta(0, $dcf['terminal_value'][0], self::DELTA);
        $this->assertEqualsWithDelta(1.1, $dcf['discount_factor'][0], 0.000001);
        $this->assertEqualsWithDelta(1.21, $dcf['discount_factor'][1], 0.000001);
        $this->assertEqualsWithDelta(-230 / 1.1 + 1925 / 1.21, $dcf['npv'], self::DELTA);
        // The discounted cash flow of each year, their sum (the enterprise value = NPV) and the share of the terminal value.
        $this->assertEqualsWithDelta(-230 / 1.1, $dcf['discounted'][0], self::DELTA);
        $this->assertEqualsWithDelta(1925 / 1.21, $dcf['discounted'][1], self::DELTA);
        $this->assertEqualsWithDelta($dcf['npv'], array_sum($dcf['discounted']), self::DELTA);
        $this->assertEqualsWithDelta(1785 / 1.21, $dcf['terminal_pv'], self::DELTA);
        // −230 + 1925 ÷ (1 + r) = 0
        $this->assertEqualsWithDelta((1925 / 230 - 1) * 100, $dcf['irr'], 0.001);
    }

    public function test_tax_is_not_taken_on_a_loss(): void
    {
        $dcf = D::results($this->input(['ebit' => $this->two(-10, 10)]))['dcf'];

        $this->assertEqualsWithDelta(0, $dcf['taxes'][0], self::DELTA);
        $this->assertEqualsWithDelta(-120 + 24 - 50 - 300, $dcf['free_cash_flow'][0], self::DELTA);
    }

    public function test_terminal_value_is_left_out_when_wacc_is_not_above_growth(): void
    {
        $dcf = D::results($this->input(['perpetual_growth_rate' => 10.0]))['dcf'];

        $this->assertFalse($dcf['terminal_ok']);
        $this->assertEqualsWithDelta(0, $dcf['terminal_value'][1], self::DELTA);
    }

    public function test_wacc_mixes_equity_and_debt(): void
    {
        $dcf = D::results($this->input(['equity_funding' => [0 => 60, 1 => 60], 'debt_funding' => [0 => 40, 1 => 40], 'cost_of_debt' => 0.15]))['dcf'];

        $this->assertEqualsWithDelta(0.6 * 0.10 + 0.4 * 0.15, $dcf['wacc'][0], 0.000001);
    }

    public function test_irr_does_not_exist_when_all_flows_have_the_same_sign(): void
    {
        $this->assertSame(null, D::irr([100, 200, 300]));
        $this->assertSame(null, D::irr([-100, -200]));
        $this->assertEqualsWithDelta(10.0, D::irr([-100, 110]), 0.001);
        $this->assertEqualsWithDelta(10.0, D::irr([-100, 0, 121]), 0.001);
    }

    public function test_payback_is_the_month_the_equity_has_come_back(): void
    {
        $collections = array_fill(0, 24, 100);
        $cashOut = [0 => 400] + array_fill(1, 23, 50);   // −300 first, then +50 a month

        $p = D::payback($collections, $cashOut, 100.0, 0.0, 24);

        // −300 + 50 × 8 = 100 → reached in the 9th month (index 8)
        $this->assertSame(8, $p['month']);
        $this->assertSame(9, $p['months']);
        $this->assertEqualsWithDelta(0.8, $p['years'], 0.001);
    }

    public function test_payback_looks_beyond_the_study_and_can_be_never(): void
    {
        $this->assertSame(null, D::payback(array_fill(0, 12, 10), array_fill(0, 12, 20), 5.0, 0.0, 12)['month']);

        $p = D::payback(array_fill(0, 12, 100), array_fill(0, 12, 190), 0.0, 0.0, 12);
        $this->assertSame(null, $p['month'], 'every month loses money, so never');

        // −500 in the first month, then +40 a month: 420 at the end of 24 months; the last 12 months (+40 each) are repeated
        // and 1000 is reached after 38 months of +40 (−500 + 40 × 38).
        $p = D::payback([0 => 0] + array_fill(1, 23, 100), [0 => 500] + array_fill(1, 23, 60), 1000.0, 0.0, 24);
        $this->assertSame(38, $p['month']);
    }

    public function test_liquidity_and_activity_ratios(): void
    {
        $twelve = fn (float $v) => array_fill(0, 12, $v);
        $r = D::ratios([
            'years'               => [0 => range(0, 11)],
            'revenue'             => $twelve(100),
            'gross_profit'        => $twelve(40),
            'current_assets'      => $twelve(200),
            'current_liabilities' => $twelve(100),
            'cash'                => $twelve(50),
            'receivables'         => $twelve(60),
            'inventory'           => $twelve(120),
            'payables'            => $twelve(30),
        ]);

        $this->assertEqualsWithDelta(2.0, $r['current_ratio'][0], self::DELTA);
        $this->assertEqualsWithDelta(1.1, $r['quick_ratio'][0], self::DELTA);
        $this->assertEqualsWithDelta(0.5, $r['cash_ratio'][0], self::DELTA);
        $this->assertEqualsWithDelta(100, $r['working_capital'][0], self::DELTA);
        // revenue 1200, cost of sales 720
        $this->assertEqualsWithDelta(60 / 1200 * 360, $r['dso'][0], self::DELTA);
        $this->assertEqualsWithDelta(120 / 720 * 360, $r['dio'][0], self::DELTA);
        $this->assertEqualsWithDelta(30 / 720 * 360, $r['dpo'][0], self::DELTA);
        $this->assertEqualsWithDelta(18 + 60 - 15, $r['ccc'][0], self::DELTA);
    }

    public function test_ratios_are_zero_when_there_is_nothing_to_divide_by(): void
    {
        $zero = array_fill(0, 12, 0);
        $r = D::ratios(['years' => [0 => range(0, 11)], 'revenue' => $zero, 'gross_profit' => $zero, 'current_assets' => $zero, 'current_liabilities' => $zero,
            'cash' => $zero, 'receivables' => $zero, 'inventory' => $zero, 'payables' => $zero]);

        $this->assertEqualsWithDelta(0, $r['current_ratio'][0], self::DELTA);
        $this->assertEqualsWithDelta(0, $r['dso'][0], self::DELTA);
    }

    public function test_cost_of_debt_is_weighted_by_the_loan_of_each_asset(): void
    {
        // loans: 1000 × 50 % = 500 at 10 %, 1000 × 100 % = 1000 at 20 %  →  (500 × 10 + 1000 × 20) ÷ 1500
        $cost = D::costOfDebt([
            ['amount' => 1000, 'equity_rate' => 50, 'interest_rate' => 10],
            ['amount' => 1000, 'equity_rate' => 0, 'interest_rate' => 20],
        ]);

        $this->assertEqualsWithDelta((500 * 0.10 + 1000 * 0.20) / 1500, $cost, 0.000001);
        $this->assertEqualsWithDelta(0, D::costOfDebt([['amount' => 1000, 'equity_rate' => 100, 'interest_rate' => 10]]), 0.000001);
        $this->assertEqualsWithDelta(0, D::costOfDebt([]), 0.000001);
    }

    public function test_periods_can_be_months_while_the_discounted_cash_flow_stays_yearly(): void
    {
        $months = [];
        foreach (range(0, 23) as $m) {
            $months[$m] = [$m];
        }
        $r = D::results($this->input(['periods' => $months]));

        $this->assertCount(24, $r['revenue']);
        $this->assertEqualsWithDelta(100, $r['revenue'][5], self::DELTA);
        $this->assertEqualsWithDelta(10.0, $r['growth'][12], self::DELTA, 'month on month: 100 → 110');
        $this->assertEqualsWithDelta(40, $r['profit']['gross_profit']['percent'][3], self::DELTA, '% of the revenue of the same month');
        $this->assertCount(2, $r['dcf']['free_cash_flow'], 'the valuation still has one figure per year');
        $this->assertEqualsWithDelta(-230, $r['dcf']['free_cash_flow'][0], self::DELTA);
    }

    // ── Part 2: the previous ZAVERO's studies (tests/Fixtures/stage7_golden.json) ──────────────────────────────

    public function test_ten_old_studies_give_the_same_free_cash_flow_wacc_npv_and_irr(): void
    {
        $golden = json_decode(file_get_contents(__DIR__.'/../../Fixtures/stage7_golden.json'), true);
        $this->assertGreaterThan(5, count($golden['cases']));

        foreach ($golden['cases'] as $case) {
            $n = count($case['fcf']);
            $years = $zero = $at = [];
            foreach (range(0, $n - 1) as $y) {
                $years[$y] = range(12 * $y, 12 * $y + 11);
            }
            $zero = array_fill_keys(range(0, 12 * $n - 1), 0);
            $at = fn (array $list) => array_combine(array_map(fn ($y) => 12 * $y, array_keys($list)), $list) + $zero;

            $dcf = D::results([
                'years' => $years, 'revenue' => $zero, 'gross_profit' => $zero, 'ebitda' => $zero, 'net_profit' => $zero,
                'ebit' => $at($case['years']['ebit']), 'depreciation' => $at($case['years']['dep']), 'capex' => $at($case['years']['capex']),
                'cogs_lines' => [], 'expense_lines' => [], 'net_change_working_capital' => $case['years']['nwc'],
                'tax_rate' => $case['tax'], 'perpetual_growth_rate' => $case['g'], 'return_rate' => $case['ret'], 'cost_of_debt' => $case['kd'],
                'equity_funding' => $case['years']['eq'], 'debt_funding' => $case['years']['db'],
            ])['dcf'];

            foreach ($case['fcf'] as $i => $expected) {
                $this->assertEqualsWithDelta($expected, $dcf['free_cash_flow'][$i], self::DELTA, "project {$case['pid']} free cash flow, year $i");
                $this->assertEqualsWithDelta($case['wacc'][$i], $dcf['wacc'][$i], 0.0000001, "project {$case['pid']} WACC, year $i");
            }
            $this->assertEqualsWithDelta($case['npv'], $dcf['npv'], self::DELTA, "project {$case['pid']} NPV");
            if ($case['irr'] === null) {
                $this->assertSame(null, $dcf['irr'], "project {$case['pid']} has no IRR");
            } else {
                $this->assertEqualsWithDelta($case['irr'], $dcf['irr'], 0.001, "project {$case['pid']} IRR");
            }
        }
    }
}
