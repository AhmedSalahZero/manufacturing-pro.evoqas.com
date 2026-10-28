<?php

namespace Tests\Unit\Calculations;

use App\Calculations\FgCostingCalculator;
use App\Calculations\FinancialStatementsCalculator as F;
use App\Calculations\TaxCalculator;
use App\Calculations\Timeline;
use PHPUnit\Framework\TestCase;

// Cost of goods sold, VAT, corporate tax and the three financial statements.
// Part 1 is worked by hand. Part 2 compares with what the previous ZAVERO saved for the same inputs
// (tests/Fixtures/stage6_golden.json: 6 products, 3 VAT statements, 3 corporate tax statements and 2 whole studies, within 0.01).
// Run:  php artisan test --filter=FinancialStatementsTest
class FinancialStatementsTest extends TestCase
{
    private const DELTA = 0.01;

    private function assertSeries(array $expected, array $actual, string $label): void
    {
        $this->assertNotEmpty($expected, $label);

        foreach ($expected as $month => $value) {
            $this->assertEqualsWithDelta((float) $value, (float) ($actual[$month] ?? 0), self::DELTA, "$label, month $month");
        }
    }

    // ── Part 1: worked by hand ───────────────────────────────────────────────

    public function test_vat_is_paid_the_month_after_and_a_credit_is_carried_forward(): void
    {
        $s = TaxCalculator::vatStatement([0 => 1000, 1 => 500, 2 => -2000, 3 => 300], 0.0, [0, 1, 2, 3, 4]);

        $this->assertEqualsWithDelta(1000, $s['end_balance'][0], self::DELTA);
        $this->assertEqualsWithDelta(1000, $s['payment'][1], self::DELTA, 'last month is paid');
        $this->assertEqualsWithDelta(500, $s['end_balance'][1], self::DELTA);
        $this->assertEqualsWithDelta(500, $s['payment'][2], self::DELTA);
        $this->assertEqualsWithDelta(-2000, $s['end_balance'][2], self::DELTA, 'purchases carried more VAT than sales: a credit');
        $this->assertEqualsWithDelta(0, $s['payment'][3], self::DELTA, 'a credit is never paid out');
        $this->assertEqualsWithDelta(-1700, $s['end_balance'][3], self::DELTA, 'the credit is set against the next month');
        $this->assertEqualsWithDelta(0, $s['payment'][4], self::DELTA);
    }

    public function test_the_opening_vat_is_paid_in_the_second_month(): void
    {
        $s = TaxCalculator::vatStatement([], 7000.0, [0, 1, 2]);

        $this->assertEqualsWithDelta(0, $s['payment'][0], self::DELTA);
        $this->assertEqualsWithDelta(7000, $s['payment'][1], self::DELTA);
        $this->assertEqualsWithDelta(0, $s['end_balance'][1], self::DELTA);
    }

    public function test_tax_on_the_year_nets_a_loss_month_against_the_profit_months(): void
    {
        $profit = [0 => 100, 1 => -300, 2 => 200];

        $year = TaxCalculator::corporateTax($profit, [0 => [0, 1, 2]], [0 => 2], 20.0, TaxCalculator::BASIS_YEAR);
        $monthly = TaxCalculator::corporateTax($profit, [0 => [0, 1, 2]], [0 => 2], 20.0, TaxCalculator::BASIS_MONTHLY_PROFIT);

        $this->assertEqualsWithDelta(0, $year[2], self::DELTA, 'the year as a whole made a loss');
        $this->assertEqualsWithDelta(60, $monthly[2], self::DELTA, 'the old way taxes the two profit months');
        $this->assertEqualsWithDelta(120, TaxCalculator::corporateTax([0 => 600], [0 => [0]], [0 => 0], 20.0, TaxCalculator::BASIS_YEAR)[0], self::DELTA);
    }

    public function test_the_withheld_tax_is_deducted_and_the_rest_is_paid_in_april(): void
    {
        $s = TaxCalculator::corporateTaxStatement([0 => 30, 1 => 20], [2 => 100], 0.0, range(0, 5), [2 => 4], null);

        $this->assertEqualsWithDelta(-50, $s['end_balance'][1], self::DELTA, 'withheld tax builds up as a credit');
        $this->assertEqualsWithDelta(50, $s['end_balance'][2], self::DELTA, '100 tax less 50 withheld');
        $this->assertEqualsWithDelta(0, $s['payment'][3], self::DELTA);
        $this->assertEqualsWithDelta(50, $s['payment'][4], self::DELTA);
        $this->assertEqualsWithDelta(0, $s['end_balance'][4], self::DELTA);
    }

    public function test_when_more_was_withheld_than_the_tax_nothing_is_paid(): void
    {
        $s = TaxCalculator::corporateTaxStatement([0 => 40], [2 => 10], 0.0, range(0, 5), [2 => 4], null);

        $this->assertEqualsWithDelta(0, $s['payment'][4], self::DELTA);
        $this->assertEqualsWithDelta(-30, $s['end_balance'][5], self::DELTA, 'the difference stays as a credit');
    }

    public function test_the_opening_tax_payable_can_be_paid_in_its_own_month(): void
    {
        $s = TaxCalculator::corporateTaxStatement([], [], 3000.0, range(0, 5), [], 3);

        $this->assertEqualsWithDelta(3000, $s['payment'][3], self::DELTA);
        $this->assertEqualsWithDelta(0, $s['end_balance'][3], self::DELTA);
        $this->assertEqualsWithDelta(3000, $s['end_balance'][2], self::DELTA);
    }

    public function test_the_april_after_a_month(): void
    {
        $t = Timeline::forStudy('2026-03-01', 3, '2026-03-01');

        $this->assertSame(13, TaxCalculator::aprilOfNextYear($t, 9), 'December 2026 → April 2027');
        $this->assertSame(1, TaxCalculator::firstAprilOfFirstYear($t));
        $this->assertSame(null, TaxCalculator::firstAprilOfFirstYear(Timeline::forStudy('2026-06-01', 3, '2026-06-01')), 'a study starting in June has no April in its first year');
    }

    public function test_finished_goods_cost_is_shared_between_units_sold_and_units_in_stock(): void
    {
        $r = FgCostingCalculator::calculate(
            [0 => 20, 1 => 15],
            [0 => 5, 1 => 15],
            [FgCostingCalculator::RAW_MATERIAL => 100.0],
            [
                FgCostingCalculator::RAW_MATERIAL => [0 => 200, 1 => 0],
                FgCostingCalculator::DIRECT_LABOR => [0 => 0, 1 => 0],
                FgCostingCalculator::OVERHEADS    => [0 => 0, 1 => 0],
                FgCostingCalculator::DEPRECIATION => [0 => 40, 1 => 0],
            ],
        );
        $raw = $r['statements'][FgCostingCalculator::RAW_MATERIAL];

        $this->assertEqualsWithDelta(300, $raw['total_available_manufacturing_expenses'][0], self::DELTA);
        $this->assertEqualsWithDelta(15, $raw['manufacturing_expenses_per_unit'][0], self::DELTA);
        $this->assertEqualsWithDelta(75, $raw['cogs'][0], self::DELTA);
        $this->assertEqualsWithDelta(225, $raw['end_balance'][0], self::DELTA);
        $this->assertEqualsWithDelta(225, $raw['cogs'][1], self::DELTA, 'everything left is sold');
        $this->assertEqualsWithDelta(0, $raw['end_balance'][1], self::DELTA);
        $this->assertEqualsWithDelta(75, $r['inventory']['cogs'][0], self::DELTA);
        $this->assertEqualsWithDelta(225, $r['inventory']['end_balance'][0], self::DELTA);
    }

    public function test_depreciation_is_followed_but_not_added_to_the_stock_value_or_the_cost_of_goods_sold(): void
    {
        $r = FgCostingCalculator::calculate(
            [0 => 20],
            [0 => 5],
            [],
            [
                FgCostingCalculator::RAW_MATERIAL => [0 => 200],
                FgCostingCalculator::DIRECT_LABOR => [0 => 0],
                FgCostingCalculator::OVERHEADS    => [0 => 0],
                FgCostingCalculator::DEPRECIATION => [0 => 40],
            ],
        );

        $this->assertEqualsWithDelta(10, $r['statements'][FgCostingCalculator::DEPRECIATION]['cogs'][0], self::DELTA, 'a quarter of 40 was sold');
        $this->assertEqualsWithDelta(50, $r['inventory']['cogs'][0], self::DELTA, 'only the raw material counts (200 / 20 x 5)');
    }

    public function test_the_income_statement_of_three_months(): void
    {
        $inc = F::incomeStatement([
            'months' => [0, 1, 2],
            'revenue' => ['a' => [0 => 100, 1 => 200, 2 => 300]],
            'cogs' => ['raw_material' => [0 => 40, 1 => 80, 2 => 120], 'direct_labor' => [], 'manufacturing_overheads' => []],
            'expenses' => ['general-expenses' => [0 => 20, 1 => 20, 2 => 20]],
            'cogs_depreciation' => [0 => 5, 1 => 5, 2 => 5],
            'admin_depreciation' => [0 => 2, 1 => 2, 2 => 2],
            'finance' => ['loan' => [0 => 1, 1 => 1, 2 => 1]],
            'tax_rate' => 20.0, 'tax_basis' => TaxCalculator::BASIS_YEAR,
            'years' => [0 => [0, 1, 2]], 'tax_booked_at' => [0 => 2],
            'opening_retained_earnings' => 50.0,
        ]);

        $this->assertEqualsWithDelta(120, $inc['gross_profit'][1], self::DELTA);
        $this->assertEqualsWithDelta(107, $inc['ebitda'][1], self::DELTA, 'gross profit + depreciation - operating expenses');
        $this->assertEqualsWithDelta(100, $inc['ebit'][1], self::DELTA);
        $this->assertEqualsWithDelta(99, $inc['ebt'][1], self::DELTA);
        $this->assertEqualsWithDelta(59.4, $inc['tax'][2], self::DELTA, '20% of the year: 39 + 99 + 159');
        $this->assertEqualsWithDelta(0, $inc['tax'][0], self::DELTA);
        $this->assertEqualsWithDelta(99.6, $inc['net_profit'][2], self::DELTA);
        $this->assertEqualsWithDelta(50, $inc['retained_earnings'][0], self::DELTA, 'the profit of the month itself is shown apart');
        $this->assertEqualsWithDelta(89, $inc['retained_earnings'][1], self::DELTA);
        $this->assertEqualsWithDelta(188, $inc['retained_earnings'][2], self::DELTA);
    }

    public function test_cash_never_goes_below_zero_the_owners_put_in_the_difference(): void
    {
        $c = F::cashFlow(['months' => [0, 1, 2], 'opening_cash' => 100.0, 'cash_in' => ['a' => [0 => 10, 2 => 50]], 'cash_out' => ['a' => [0 => 50, 1 => 100, 2 => 20]]]);

        $this->assertEqualsWithDelta(60, $c['end_balance'][0], self::DELTA);
        $this->assertEqualsWithDelta(-40, $c['net_before_injection'][1], self::DELTA);
        $this->assertEqualsWithDelta(40, $c['injection'][1], self::DELTA);
        $this->assertEqualsWithDelta(0, $c['end_balance'][1], self::DELTA);
        $this->assertEqualsWithDelta(30, $c['end_balance'][2], self::DELTA);
    }

    public function test_the_balance_sheet_check_is_zero_when_it_balances_and_shows_the_difference_when_not(): void
    {
        $zero = [0 => 0, 1 => 0];
        $in = ['months' => [0, 1], 'net_fixed_assets' => [0 => 50, 1 => 50], 'projects_under_progress' => $zero, 'cash' => [0 => 50, 1 => 60], 'customer_receivables' => $zero,
            'fg_inventory' => $zero, 'rm_inventory' => $zero, 'other_debtors' => $zero, 'supplier_payables' => [0 => 10, 1 => 10], 'fixed_asset_creditors' => $zero,
            'vat' => $zero, 'credit_withhold' => $zero, 'salary_taxes' => $zero, 'corporate_tax' => $zero, 'other_creditors' => $zero, 'loans' => $zero, 'other_long_term' => $zero,
            'paid_up_capital' => [0 => 90, 1 => 90], 'additional_paid_up_capital' => $zero, 'legal_reserve' => $zero, 'retained_earnings' => $zero, 'profit_of_period' => [0 => 0, 1 => 10]];

        $b = F::balanceSheet($in);
        $this->assertEqualsWithDelta(100, $b['total_assets'][0], self::DELTA);
        $this->assertEqualsWithDelta(0, $b['check'][0], self::DELTA);
        $this->assertEqualsWithDelta(0, $b['check'][1], self::DELTA);

        $in['cash'][1] = 75;
        $this->assertEqualsWithDelta(15, F::balanceSheet($in)['check'][1], self::DELTA);
    }

    public function test_the_yearly_change_of_working_capital(): void
    {
        $this->assertEquals([0 => -50.0, 1 => -30.0], F::yearlyChange([2 => 150, 5 => 180], 100.0, [0 => 2, 1 => 5], true), 'more customers owing us takes cash');
        $this->assertEquals([0 => 50.0, 1 => 30.0], F::yearlyChange([2 => 150, 5 => 180], 100.0, [0 => 2, 1 => 5], false), 'more owed to suppliers gives cash');
    }

    public function test_the_helpers(): void
    {
        $this->assertEquals([0 => 4, 1 => 6], F::add([[0 => 1, 1 => 2], [0 => 3, 1 => 4]], [0, 1]));
        $this->assertEquals([0 => 1, 1 => 3], F::accumulate([0 => 1, 1 => 2], [0, 1]));
        $this->assertEquals([0 => 0, 1 => 50.0], F::percentOf([0 => 0, 1 => 200], [0 => 5, 1 => 100], [0, 1]));
        $this->assertEquals([0 => 3, 1 => 7], F::byYear([0 => 1, 1 => 2, 2 => 3, 3 => 4], [0 => [0, 1], 1 => [2, 3]], 'sum'));
        $this->assertEquals([0 => 2, 1 => 4], F::byYear([0 => 1, 1 => 2, 2 => 3, 3 => 4], [0 => [0, 1], 1 => [2, 3]], 'end'));
        $this->assertEquals([0 => 1, 1 => 3], F::byYear([0 => 1, 1 => 2, 2 => 3, 3 => 4], [0 => [0, 1], 1 => [2, 3]], 'start'));
    }

    // ── Part 2: the results the previous ZAVERO saved ────────────────────────

    private function golden(): array
    {
        return json_decode(file_get_contents(dirname(__DIR__, 2).'/Fixtures/stage6_golden.json'), true);
    }

    public function test_the_fixture_is_there(): void
    {
        $g = $this->golden();
        $this->assertCount(6, $g['fg']);
        $this->assertCount(3, $g['vat']);
        $this->assertCount(3, $g['corporate_tax']);
        $this->assertCount(2, $g['statements']);
    }

    public function test_cost_of_goods_sold_matches_the_old_results(): void
    {
        foreach ($this->golden()['fg'] as $case) {
            $r = FgCostingCalculator::calculate($case['available'], $case['sold'], $case['opening'], $case['spent']);

            foreach (FgCostingCalculator::TYPES as $type) {
                foreach (['cogs', 'end_balance'] as $column) {
                    $this->assertSeries($case['expected'][$type][$column], $r['statements'][$type][$column], "{$case['label']} $type $column");
                }
            }
            $this->assertSeries($case['expected']['inventory']['cogs'], $r['inventory']['cogs'], "{$case['label']} total cost of goods sold");
            $this->assertSeries($case['expected']['inventory']['end_balance'], $r['inventory']['end_balance'], "{$case['label']} stock value");
        }
    }

    public function test_the_vat_statement_matches_the_old_results(): void
    {
        foreach ($this->golden()['vat'] as $case) {
            $s = TaxCalculator::vatStatement($case['additions'], (float) $case['opening'], $case['months']);

            foreach (['total_due', 'payment', 'end_balance'] as $column) {
                $this->assertSeries($case['expected'][$column], $s[$column], "{$case['label']} VAT $column");
            }
        }
    }

    public function test_the_corporate_tax_statement_matches_the_old_results(): void
    {
        foreach ($this->golden()['corporate_tax'] as $case) {
            $s = TaxCalculator::corporateTaxStatement($case['withheld'], $case['tax'], (float) $case['opening'], $case['months'], $case['paid_in'], $case['opening_paid_in']);

            foreach (['total_due', 'payment', 'end_balance'] as $column) {
                $this->assertSeries($case['expected'][$column], $s[$column], "{$case['label']} corporate tax $column");
            }
        }
    }

    public function test_the_income_statement_cash_flow_and_balance_sheet_match_the_old_results(): void
    {
        foreach ($this->golden()['statements'] as $case) {
            $label = $case['label'];
            $income = $case['income'];
            $income['tax_basis'] = TaxCalculator::BASIS_MONTHLY_PROFIT;   // the way the old application worked the tax out
            $i = F::incomeStatement($income);

            $this->assertSeries($case['expected_income']['ebt'], $i['ebt'], "$label profit before tax");
            $this->assertSeries($case['expected_income']['net_profit'], $i['net_profit'], "$label net profit");
            $this->assertSeries($case['expected_income']['retained_earnings'], $i['retained_earnings'], "$label retained earnings");

            $c = F::cashFlow($case['cash']);
            $this->assertSeries($case['expected_cash']['cash_end_balance'], $c['end_balance'], "$label cash at the end");
            $this->assertSeries($case['expected_cash']['working_capital_injection'], $c['injection'], "$label owners' top-up");
            $this->assertSeries($case['expected_cash']['total_cash_out'], $c['cash_out'], "$label total cash out");

            $b = F::balanceSheet($case['balance_in']);
            foreach ($b['check'] as $month => $gap) {
                $this->assertEqualsWithDelta(0, $gap, 0.5, "$label balance sheet check, month $month");
            }
        }
    }

    public function test_a_vat_credit_is_an_asset_and_a_vat_debt_is_a_liability(): void
    {
        $months = [0, 1, 2];
        $zero = array_fill_keys($months, 0);
        $b = F::balanceSheet([
            'months' => $months, 'net_fixed_assets' => $zero, 'projects_under_progress' => $zero, 'cash' => $zero, 'customer_receivables' => $zero,
            'fg_inventory' => $zero, 'rm_inventory' => $zero, 'other_debtors' => $zero, 'supplier_payables' => $zero, 'fixed_asset_creditors' => $zero,
            'vat' => [0 => 500, 1 => -300, 2 => 0], 'credit_withhold' => $zero, 'salary_taxes' => $zero, 'corporate_tax' => $zero, 'other_creditors' => $zero,
            'loans' => $zero, 'other_long_term' => $zero, 'paid_up_capital' => $zero, 'additional_paid_up_capital' => $zero, 'legal_reserve' => $zero,
            'retained_earnings' => [0 => -500, 1 => 300, 2 => 0], 'profit_of_period' => $zero,
        ]);

        $this->assertEqualsWithDelta(500, $b['vat_payable'][0], self::DELTA);
        $this->assertEqualsWithDelta(0, $b['vat_receivable'][0], self::DELTA);
        $this->assertEqualsWithDelta(0, $b['vat_payable'][1], self::DELTA);
        $this->assertEqualsWithDelta(300, $b['vat_receivable'][1], self::DELTA);
        $this->assertEqualsWithDelta(300, $b['total_current_assets'][1], self::DELTA, 'the credit is a current asset');
        $this->assertEqualsWithDelta(500, $b['total_current_liabilities'][0], self::DELTA);
        $this->assertEqualsWithDelta(0, $b['total_current_liabilities'][1], self::DELTA, 'no minus inside the liabilities');
        foreach ($months as $m) {
            $this->assertEqualsWithDelta(0, $b['check'][$m], self::DELTA, "balanced, month $m");
        }
    }
}
