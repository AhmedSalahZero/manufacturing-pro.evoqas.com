<?php

namespace Tests\Unit\Calculations;

use App\Calculations\ExpenseCalculator;
use App\Calculations\ManpowerCalculator;
use App\Calculations\ProductAllocation;
use App\Calculations\Timeline;
use PHPUnit\Framework\TestCase;

// Staff cost, expenses and how costs are shared between products.
// Part 1 is worked by hand; part 2 compares the engine with results the previous ZAVERO saved for real (test) studies
// (tests/Fixtures/stage3_golden.json: 6 staff positions and 10 expenses, every monthly figure within 0.01).
// Run:  php artisan test --filter=StaffAndExpensesTest
class StaffAndExpensesTest extends TestCase
{
    private const DELTA = 0.01;

    private function timeline(string $start = '2026-01-01', int $years = 1, string $operation = '2026-01-01'): Timeline
    {
        return Timeline::forStudy($start, $years, $operation);
    }

    private function assertSeries(array $expected, array $actual, string $label): void
    {
        $this->assertNotEmpty($expected, $label);

        foreach ($expected as $month => $value) {
            $this->assertEqualsWithDelta($value, $actual[$month] ?? 0, self::DELTA, "$label, month $month");
        }
    }

    // ── Part 1: worked by hand ───────────────────────────────────────────────

    public function test_headcount_salary_and_employer_cost(): void
    {
        // 2 people today, 1 more hired in month 0, net salary 1000, income tax 5 % + insurance 5 %.
        $r = ManpowerCalculator::calculate($this->timeline(), 2, [0 => 1], 1000, 0, 5, 5);

        $this->assertEquals(3, $r['accumulated_manpower_counts'][0]);
        $this->assertEquals(3, $r['accumulated_manpower_counts'][5]);
        $this->assertEqualsWithDelta(3000, $r['salary_payments'][0], 1e-9);
        $this->assertEqualsWithDelta(3000 / 0.9, $r['salary_expenses'][0], 1e-9);          // the employer pays 10 % on top: net = 90 % of the cost
        $this->assertEqualsWithDelta(3000 / 0.9 * 0.1, $r['tax_and_social_insurance_statement']['monthly']['expense'][0], 1e-9);
        $this->assertEqualsWithDelta(0, $r['tax_and_social_insurance_statement']['monthly']['payment'][0], 1e-9);       // paid a month later
        $this->assertEqualsWithDelta(3000 / 0.9 * 0.1, $r['tax_and_social_insurance_statement']['monthly']['payment'][1], 1e-9);
    }

    public function test_the_net_salary_grows_every_12_operation_months_and_hirings_add_up(): void
    {
        $r = ManpowerCalculator::calculate($this->timeline(), 0, [0 => 2, 13 => 1], 1000, 10, 0, 0);

        $this->assertEquals(1000 * 2, $r['salary_payments'][11]);
        $this->assertEqualsWithDelta(1100 * 2, $r['salary_payments'][12], 1e-9);          // first increase after 12 months
        $this->assertEqualsWithDelta(1100 * 3, $r['salary_payments'][13], 1e-9);          // one more person from month 13
        $this->assertEquals(3, $r['accumulated_manpower_counts'][23]);
    }

    public function test_the_increase_counts_from_the_operation_start_not_the_study_start(): void
    {
        // Study starts in January, operation in April (index 3): the first increase is in month 15.
        $r = ManpowerCalculator::calculate($this->timeline('2026-01-01', 2, '2026-04-01'), 1, [], 1000, 10, 0, 0);

        $this->assertSame(3, array_key_first($r['salary_payments']));
        $this->assertEquals(1000, $r['salary_payments'][14]);
        $this->assertEqualsWithDelta(1100, $r['salary_payments'][15], 1e-9);
    }

    public function test_costs_are_shared_by_sales_or_by_typed_percentages(): void
    {
        $revenue = ProductAllocation::revenueShares([1 => [0 => 300, 1 => 0], 2 => [0 => 100, 1 => 50]]);
        $this->assertEqualsWithDelta(0.75, $revenue[1][0], 1e-12);
        $this->assertEqualsWithDelta(0.25, $revenue[2][0], 1e-12);
        $this->assertEqualsWithDelta(1.0, $revenue[2][1], 1e-12);

        $bySales = ProductAllocation::split($revenue, [], [1, 2], [0 => 1000, 1 => 1000], true);
        $this->assertEqualsWithDelta(750, $bySales[1][0], 1e-9);
        $this->assertEqualsWithDelta(250, $bySales[2][0], 1e-9);
        $this->assertEqualsWithDelta(1000, $bySales[2][1], 1e-9);

        // Typed 60 / 40, but product 2 only starts selling in month 1: in month 0 product 1 takes everything.
        $typed = ProductAllocation::typedShares([1 => 60, 2 => 40], [1 => 0, 2 => 1], [0, 1]);
        $this->assertEqualsWithDelta(1.0, $typed[1][0], 1e-12);
        $this->assertEqualsWithDelta(0.0, $typed[2][0], 1e-12);
        $this->assertEqualsWithDelta(0.6, $typed[1][1], 1e-12);
        $this->assertEqualsWithDelta(0.4, $typed[2][1], 1e-12);

        $byTyped = ProductAllocation::split([], $typed, [1, 2], [0 => 1000, 1 => 1000], false);
        $this->assertEqualsWithDelta(1000, $byTyped[1][0], 1e-9);
        $this->assertEqualsWithDelta(400, $byTyped[2][1], 1e-9);
    }

    public function test_a_fixed_monthly_expense_grows_every_3_6_or_12_months_and_vat_is_added_unless_deductible(): void
    {
        $yearly = ExpenseCalculator::fixedMonthly(100, 0, 13, 'annually', 10, false, 14, 0);
        $this->assertEquals(100, $yearly['total_before_vat'][11]);
        $this->assertEqualsWithDelta(110, $yearly['total_before_vat'][12], 1e-9);
        $this->assertEqualsWithDelta(114, $yearly['total_after_vat'][0], 1e-9);
        $this->assertEqualsWithDelta(14, $yearly['total_vat'][0], 1e-9);

        $quarterly = ExpenseCalculator::fixedMonthly(100, 2, 8, 'quarterly', 10, true, 14, 5);
        $this->assertEquals(100, $quarterly['total_before_vat'][4]);
        $this->assertEqualsWithDelta(110, $quarterly['total_before_vat'][5], 1e-9);       // counted from its own start month
        $this->assertEqualsWithDelta(121, $quarterly['total_before_vat'][8], 1e-9);
        $this->assertEqualsWithDelta(100, $quarterly['total_after_vat'][2], 1e-9);        // deductible VAT is not a cost
        $this->assertEqualsWithDelta(5, $quarterly['withhold_amounts'][2], 1e-9);
    }

    public function test_a_one_time_expense_is_spread_over_its_months(): void
    {
        $r = ExpenseCalculator::oneTime(1200, 12, 2, false, 14);

        $this->assertCount(12, $r['monthly_one_time']);
        $this->assertEqualsWithDelta(114, $r['monthly_one_time'][2], 1e-9);               // (1200 + 14 %) / 12
        $this->assertEqualsWithDelta(114, $r['monthly_one_time'][13], 1e-9);
        $this->assertEqualsWithDelta(0, $r['end_balance'][13], 1e-9);

        $deductible = ExpenseCalculator::oneTime(1200, 6, 0, true, 14);
        $this->assertEqualsWithDelta(200, $deductible['monthly_one_time'][0], 1e-9);
    }

    public function test_percentage_and_cost_per_unit_use_only_the_chosen_months_and_products(): void
    {
        $sales = [1 => [0 => 1000, 1 => 2000, 2 => 3000], 2 => [0 => 500, 1 => 500, 2 => 500]];

        $r = ExpenseCalculator::percentageOfRevenue($sales, 1, 2, 10);
        $this->assertEquals([1 => 250.0, 2 => 350.0], $r['expense_amounts']);
        $this->assertEquals([1 => 200.0, 2 => 300.0], $r['expense_allocations'][1]);

        $units = ExpenseCalculator::costPerUnit([1 => [0 => 10, 1 => 20]], 0, 5, 3);
        $this->assertEquals([0 => 30.0, 1 => 60.0], $units['expense_amounts']);
    }

    public function test_payment_terms(): void
    {
        $timeline = $this->timeline();
        $amounts = [0 => 10, 1 => 10, 2 => 10, 3 => 10, 4 => 10, 5 => 10, 6 => 10];

        $this->assertEquals($amounts, ExpenseCalculator::payments('cash', [], $amounts, $timeline));
        // Each block of 3 months is paid together in the block's FIRST month (as the old application did).
        $this->assertEquals([0 => 30, 3 => 30, 6 => 10], ExpenseCalculator::payments('quarterly', [], $amounts, $timeline));
        $this->assertEquals([0 => 60, 6 => 10], ExpenseCalculator::payments('semi-annually', [], $amounts, $timeline));

        // 50 % at once, 50 % after 30 days (one month later).
        $split = ExpenseCalculator::payments('customize', [0 => 50, 30 => 50], [0 => 100], $timeline);
        $this->assertSame([0, 1], array_keys($split));
        $this->assertEqualsWithDelta(50, $split[0], 1e-9);
        $this->assertEqualsWithDelta(50, $split[1], 1e-9);
        // 60 days after the last month of the study falls outside the study and is not counted.
        $this->assertSame([], ExpenseCalculator::payments('customize', [60 => 100], [83 => 100], $timeline));
    }

    public function test_the_payable_statement_balances(): void
    {
        $statement = ExpenseCalculator::statement([0 => 100, 1 => 100], [0 => 14, 1 => 14], [0 => 0, 1 => 228], [], $this->timeline());
        $monthly = $statement['monthly'];

        $this->assertEquals([0 => 0, 1 => 114], $monthly['beginning_balance']);
        $this->assertEquals([0 => 114, 1 => 228], $monthly['total_due']);
        $this->assertEquals([0 => 114, 1 => 0], $monthly['end_balance']);
    }

    public function test_what_is_paid_after_the_last_expense_month_is_still_owed_until_it_is_paid(): void
    {
        // A one-time expense of 1 200 in month 3, paid 50 % after 30 days and 50 % after 60 days.
        $timeline = $this->timeline('2026-01-01', 1, '2026-01-01');
        $payments = ExpenseCalculator::payments('customize', [30 => 50, 60 => 50], [3 => 1200], $timeline);
        $this->assertEquals([4 => 600, 5 => 600], $payments);

        $monthly = ExpenseCalculator::statement([3 => 1200], [3 => 0], $payments, [], $timeline)['monthly'];

        // Owed in month 3, half still owed in month 4, nothing owed from month 5: the balance is not dropped after month 3.
        $this->assertEquals([3 => 1200, 4 => 600, 5 => 0], $monthly['end_balance']);
        $this->assertEquals([3 => 1200, 4 => 1200, 5 => 600], $monthly['total_due']);   // total due = the balance brought forward + the new expense
        $this->assertEquals([3 => 0, 4 => 600, 5 => 600], $monthly['payment']);

        // Payments that skip a month (60 and 120 days) keep the balance in the month between them too.
        $gaps = ExpenseCalculator::payments('customize', [60 => 50, 120 => 50], [3 => 1200], $timeline);
        $statement = ExpenseCalculator::statement([3 => 1200], [3 => 0], $gaps, [], $timeline)['monthly'];
        $this->assertEquals([3 => 1200, 4 => 1200, 5 => 600, 6 => 600, 7 => 0], $statement['end_balance']);
    }

    // ── Part 2: the previous application's saved results ─────────────────────

    private function golden(): array
    {
        return json_decode(file_get_contents(dirname(__DIR__, 2).'/Fixtures/stage3_golden.json'), true);
    }

    private function timelineOf(array $case): Timeline
    {
        return Timeline::forStudy($case['study']['start'], $case['study']['duration'], $case['study']['operation_start']);
    }

    public function test_the_fixture_is_there(): void
    {
        $golden = $this->golden();

        $this->assertCount(6, $golden['manpower']);
        $this->assertCount(10, $golden['expenses']);
    }

    public function test_staff_cost_matches_the_old_results(): void
    {
        foreach ($this->golden()['manpower'] as $case) {
            $in = $case['inputs'];
            $r = ManpowerCalculator::calculate($this->timelineOf($case), $in['existing'], $in['hirings'], $in['salary'], $in['increase'], $in['tax'], $in['insurance']);
            $x = $case['expected'];

            $this->assertSeries($x['expenses'], $r['salary_expenses'], $case['label'].' employer cost');
            $this->assertSeries($x['payments'], $r['salary_payments'], $case['label'].' salaries paid');
            $this->assertSeries($x['counts'], $r['accumulated_manpower_counts'], $case['label'].' headcount');
            $this->assertSeries($x['tax_expense'], $r['tax_and_social_insurance_statement']['monthly']['expense'], $case['label'].' tax + insurance');
            $this->assertSeries($x['tax_payment'], $r['tax_and_social_insurance_statement']['monthly']['payment'], $case['label'].' tax payments');
            $this->assertSeries($x['tax_end_balance'], $r['tax_and_social_insurance_statement']['monthly']['end_balance'], $case['label'].' tax payable');
        }
    }

    public function test_expenses_match_the_old_results(): void
    {
        foreach ($this->golden()['expenses'] as $case) {
            $timeline = $this->timelineOf($case);
            $x = $case['expected'];

            if ($case['kind'] === 'fixed_monthly_repeating_amount') {
                $r = ExpenseCalculator::fixedMonthly($case['amount'], $case['start'], $case['end'], $case['interval'], $case['increase'], $case['deductible'], $case['vat'], $case['withhold']);
                $payments = ExpenseCalculator::payments($case['terms'], $case['policy'], $r['total_after_vat'], $timeline);
                $withholdPayments = ExpenseCalculator::payments($case['terms'], $case['policy'], $r['withhold_amounts'], $timeline);
                $statement = ExpenseCalculator::statement($r['total_before_vat'], $r['total_vat'], $payments, $withholdPayments, $timeline)['monthly'];

                $this->assertSeries($x['before'], $r['total_before_vat'], $case['label'].' before VAT');
                $this->assertSeries($x['after'], $r['total_after_vat'], $case['label'].' after VAT');
                $this->assertSeries($x['payments'], $payments, $case['label'].' payments');
                $this->assertSeries($x['stmt_expense'], $statement['expense'], $case['label'].' statement expense');
                $this->assertSeries($x['stmt_payment'], $statement['payment'], $case['label'].' statement payment');
                $this->assertSeries($x['stmt_end_balance'], $statement['end_balance'], $case['label'].' statement balance');
            } elseif ($case['kind'] === 'one_time_expense') {
                $r = ExpenseCalculator::oneTime($case['amount'], $case['amortization'], $case['start'], $case['deductible'], $case['vat']);
                $payments = ExpenseCalculator::payments($case['terms'], $case['policy'], [$case['start'] => $case['amount'] * (1 + $case['vat'] / 100)], $timeline);

                $this->assertSeries($x['monthly'], $r['monthly_one_time'], $case['label'].' monthly');
                $this->assertSeries($x['payments'], $payments, $case['label'].' payments');
            } else {
                $r = $case['kind'] === 'cost_per_unit'
                    ? ExpenseCalculator::costPerUnit($this->keyed($case['bases']), $case['start'], $case['end'], $case['factor'])
                    : ExpenseCalculator::percentageOfRevenue($this->keyed($case['bases']), $case['start'], $case['end'], $case['factor']);
                $payments = ExpenseCalculator::payments($case['terms'], $case['policy'], $r['expense_amounts'], $timeline);

                $this->assertSeries($x['amounts'], $r['expense_amounts'], $case['label'].' amounts');
                $this->assertSeries($x['payments'], $payments, $case['label'].' payments');
            }
        }
    }

    /** JSON gives string keys for product ids; the engine only cares about the months. */
    private function keyed(array $bases): array
    {
        return array_map(fn ($series) => array_combine(array_map('intval', array_keys($series)), array_values($series)), $bases);
    }
}
