<?php

namespace Tests\Unit\Calculations;

use App\Calculations\FixedAssetCalculator;
use App\Calculations\LoanCalculator;
use App\Calculations\Timeline;
use PHPUnit\Framework\TestCase;

// Fixed assets (cost, payments, equity and loan, capitalised interest, depreciation, replacement) and the loan schedule.
// Part 1 is worked by hand; part 2 compares the engine with results the previous ZAVERO saved for real (test) studies
// (tests/Fixtures/stage4_golden.json: 8 assets, every monthly figure within 0.01).
// Run:  php artisan test --filter=FixedAssetsAndLoansTest
class FixedAssetsAndLoansTest extends TestCase
{
    private const DELTA = 0.01;

    private function timeline(string $start = '2026-01-01', int $years = 3, string $operation = '2026-01-01'): Timeline
    {
        return Timeline::forStudy($start, $years, $operation);
    }

    /** An asset with sensible defaults; $over changes what a test cares about. */
    private function asset(array $over = []): array
    {
        return array_merge([
            'count' => 1, 'amount' => 1200, 'start' => 0, 'end' => 0,
            'depreciation_years' => 2, 'admin_pct' => 0, 'manufacturing_pct' => 100,
            'replacement_rate' => 0, 'replacement_years' => 1,
            'terms' => 'cash', 'policy' => [], 'from_total' => [],
            'equity_rate' => 100, 'interest_rate' => 12, 'tenor' => 12, 'loan_interval' => 'monthly', 'loan_grace' => 0,
        ], $over);
    }

    private function calculate(array $over = [], ?Timeline $timeline = null, int $operationStart = 0, int $studyEnd = 35): array
    {
        return FixedAssetCalculator::calculate($this->asset($over), $timeline ?? $this->timeline(), $operationStart, $studyEnd);
    }

    private function assertSeries(array $expected, array $actual, string $label, bool $strict = false): void
    {
        $this->assertNotEmpty($expected, $label);

        foreach ($expected as $month => $value) {
            $this->assertEqualsWithDelta($value, $actual[$month] ?? 0, self::DELTA, "$label, month $month");
        }

        if ($strict) {
            $last = max(array_keys($expected));
            foreach ($actual as $month => $value) {
                if ($month <= $last && ! array_key_exists($month, $expected)) {
                    $this->assertEqualsWithDelta(0, $value, self::DELTA, "$label, month $month is not in the old results");
                }
            }
        }
    }

    // ── Part 1: worked by hand ───────────────────────────────────────────────

    public function test_the_cost_is_spent_in_equal_parts_over_the_months(): void
    {
        $this->assertEquals([2 => 300.0, 3 => 300.0, 4 => 300.0, 5 => 300.0], FixedAssetCalculator::execution(1200, 2, 5));
        // Same start and end month: everything in that month.
        $this->assertEquals([4 => 1200.0], FixedAssetCalculator::execution(1200, 4, 4));
    }

    public function test_cash_is_paid_as_executed(): void
    {
        $r = $this->calculate(['start' => 1, 'end' => 4]);

        $this->assertEquals($r['execution'], $r['payments']);
        $this->assertEqualsWithDelta(1200, array_sum($r['payments']), self::DELTA);
    }

    public function test_customised_payments_from_the_total_and_from_each_months_execution(): void
    {
        // 30 % of the total at the start, 70 % of each month's execution 60 days (2 months) later.
        $r = $this->calculate(['start' => 1, 'end' => 2, 'terms' => 'customize', 'policy' => [0 => 30, 60 => 70], 'from_total' => [0 => true, 60 => false]]);

        $this->assertEqualsWithDelta(360, $r['payments'][1], self::DELTA);           // 30 % of 1200
        $this->assertEqualsWithDelta(0, $r['payments'][2] ?? 0, self::DELTA);
        $this->assertEqualsWithDelta(420, $r['payments'][3], self::DELTA);           // 70 % of 600 (month 1)
        $this->assertEqualsWithDelta(420, $r['payments'][4], self::DELTA);           // 70 % of 600 (month 2)
        $this->assertEqualsWithDelta(1200, array_sum($r['payments']), self::DELTA);
    }

    public function test_customised_payments_all_from_the_total_add_up_to_the_total_price(): void
    {
        // Bought over 3 months (400 + 400 + 400); 40 % at once, 60 % 60 days later - all taken from the total price.
        $paid = FixedAssetCalculator::contractPayments([2 => 400.0, 3 => 400.0, 4 => 400.0], 1200.0, [0 => 40, 60 => 60], [0 => true, 60 => true]);

        $this->assertEqualsWithDelta(480, $paid[2], self::DELTA);    // 40 % of 1200, from the first purchase month
        $this->assertEqualsWithDelta(720, $paid[4], self::DELTA);    // 60 % of 1200, two months later
        $this->assertEqualsWithDelta(1200, array_sum($paid), self::DELTA);
    }

    public function test_customised_payments_all_from_each_months_purchase_add_up_to_the_total_price(): void
    {
        $paid = FixedAssetCalculator::contractPayments([2 => 400.0, 3 => 400.0, 4 => 400.0], 1200.0, [0 => 40, 60 => 60], [0 => false, 60 => false]);

        $this->assertEqualsWithDelta(160, $paid[2], self::DELTA);    // 40 % of month 2
        $this->assertEqualsWithDelta(160, $paid[3], self::DELTA);
        $this->assertEqualsWithDelta(160 + 240, $paid[4], self::DELTA);   // 40 % of month 4 + 60 % of month 2
        $this->assertEqualsWithDelta(240, $paid[5], self::DELTA);
        $this->assertEqualsWithDelta(240, $paid[6], self::DELTA);
        $this->assertEqualsWithDelta(1200, array_sum($paid), self::DELTA);
    }

    public function test_less_than_30_days_lands_in_the_same_month_and_45_days_is_two_months(): void
    {
        $paid = FixedAssetCalculator::contractPayments([3 => 100.0], 100.0, [15 => 40, 45 => 60], [15 => false, 45 => false]);

        $this->assertEquals([3 => 40.0, 5 => 60.0], $paid);
    }

    // ── Kinds of asset: land, construction, machine, other ──────────────────

    public function test_a_construction_pays_a_down_payment_on_the_total_and_the_rest_after_each_months_work(): void
    {
        // A factory of 10 million built in 10 months (1 million a month): 25 % down payment on the total at the start,
        // 50 % of each month's work 30 days later and 25 % of each month's work 60 days later.
        $r = $this->calculate([
            'type' => 'construction', 'count' => 1, 'amount' => 10000000, 'start' => 0, 'end' => 9, 'terms' => 'customize',
            'policy' => [0 => 25, 30 => 50, 60 => 25], 'from_total' => [0 => true, 30 => false, 60 => false],
        ]);

        $this->assertEqualsWithDelta(2500000, $r['payments'][0], self::DELTA);      // month 1: the down payment
        $this->assertEqualsWithDelta(500000, $r['payments'][1], self::DELTA);       // month 2: 50 % of month 1's work
        foreach (range(2, 10) as $month) {
            $this->assertEqualsWithDelta(750000, $r['payments'][$month], self::DELTA);   // months 3 to 11
        }
        $this->assertEqualsWithDelta(250000, $r['payments'][11], self::DELTA);      // month 12: the last 25 %
        $this->assertEqualsWithDelta(10000000, array_sum($r['payments']), self::DELTA);
    }

    public function test_a_construction_can_be_spent_on_an_s_curve(): void
    {
        $r = $this->calculate(['type' => 'construction', 'amount' => 12000, 'start' => 0, 'end' => 11, 'curve' => 's_curve', 'terms' => 'cash']);
        $e = $r['execution'];

        $this->assertCount(12, $e);
        $this->assertEqualsWithDelta(12000, array_sum($e), self::DELTA);                    // nothing is lost
        $this->assertLessThan($e[5], $e[0]);                                                 // slow start, faster in the middle
        $this->assertLessThan($e[5], $e[11]);                                                // and slow at the end
        $this->assertEqualsWithDelta($e[0], $e[11], self::DELTA);                            // the curve is symmetric
        $this->assertEqualsWithDelta($e[5], $e[6], self::DELTA);
        $this->assertEqualsWithDelta(12000 * (1 - cos(M_PI / 12)) / 2, $e[0], self::DELTA);  // about 1.7 % in the first month

        $flat = $this->calculate(['type' => 'construction', 'amount' => 12000, 'start' => 0, 'end' => 11, 'curve' => 'flat', 'terms' => 'cash']);
        $this->assertEqualsWithDelta(1000, $flat['execution'][0], self::DELTA);              // flat stays equal parts
    }

    public function test_land_is_bought_in_one_month_and_never_depreciated(): void
    {
        // The end month and the depreciation years are ignored for land.
        $r = $this->calculate(['type' => 'land', 'amount' => 5000, 'start' => 3, 'end' => 8, 'depreciation_years' => 10, 'replacement_rate' => 5, 'terms' => 'cash']);

        $this->assertEquals([3 => 5000.0], $r['execution']);
        $this->assertSame([], $r['depreciation']['total_monthly_depreciation']);
        $this->assertSame([], $r['manufacturing_depreciation']);
        $this->assertEqualsWithDelta(5000, $r['transferred_amount'], self::DELTA);
    }

    public function test_other_assets_are_bought_in_one_month_and_depreciated(): void
    {
        $r = $this->calculate(['type' => 'other', 'amount' => 1200, 'start' => 3, 'end' => 9, 'depreciation_years' => 1, 'terms' => 'cash']);

        $this->assertEquals([3 => 1200.0], $r['execution']);
        $this->assertEqualsWithDelta(100, $r['depreciation']['total_monthly_depreciation'][4], self::DELTA);   // from the month after
        $this->assertEqualsWithDelta(1200, array_sum($r['depreciation']['total_monthly_depreciation']), self::DELTA);
    }

    /** The production line: bought in January 2027, delivered in July, ready in September; 50 % at the order, 30 % at delivery, 20 % when ready. */
    private function productionLine(array $over = []): array
    {
        return $this->calculate(array_merge([
            'type' => 'machine', 'amount' => 1000, 'start' => 12, 'delivery' => 18, 'end' => 20, 'depreciation_years' => 1, 'terms' => 'milestone',
            'milestones' => ['purchase' => ['rate' => 50, 'days' => 0], 'delivery' => ['rate' => 30, 'days' => 0], 'ready' => ['rate' => 20, 'days' => 0]],
        ], $over));
    }

    public function test_a_machine_is_paid_at_the_order_the_delivery_and_when_it_is_ready(): void
    {
        $r = $this->productionLine();

        $this->assertEqualsWithDelta(500, $r['payments'][12], self::DELTA);
        $this->assertEqualsWithDelta(300, $r['payments'][18], self::DELTA);
        $this->assertEqualsWithDelta(200, $r['payments'][20], self::DELTA);
        $this->assertCount(3, $r['payments']);
        $this->assertEqualsWithDelta(1000, array_sum($r['payments']), self::DELTA);
    }

    public function test_the_advance_is_spent_when_paid_and_the_rest_when_the_machine_is_delivered(): void
    {
        $r = $this->productionLine();

        $this->assertEqualsWithDelta(500, $r['execution'][12], self::DELTA);
        $this->assertEqualsWithDelta(500, $r['execution'][18], self::DELTA);
        $this->assertEqualsWithDelta(1000, array_sum($r['execution']), self::DELTA);

        // Nothing is owed before the delivery, 200 is owed from the delivery until the last milestone is paid.
        $this->assertEqualsWithDelta(0, $r['payable'][12], self::DELTA);
        $this->assertEqualsWithDelta(0, $r['payable'][17], self::DELTA);
        $this->assertEqualsWithDelta(200, $r['payable'][18], self::DELTA);
        $this->assertEqualsWithDelta(200, $r['payable'][19], self::DELTA);
        $this->assertEqualsWithDelta(0, $r['payable'][20], self::DELTA);
        foreach ($r['payable'] as $owed) {
            $this->assertGreaterThanOrEqual(-self::DELTA, $owed);   // never a negative creditor
        }
    }

    public function test_a_machine_is_transferred_and_depreciated_only_after_it_is_ready(): void
    {
        $r = $this->productionLine();

        // Ready in month 20: transferred the month after, depreciated from the month after that (the same rule as a construction).
        $this->assertSame(21, $r['transfer_index']);
        $this->assertEqualsWithDelta(1000, $r['transferred_amount'], self::DELTA);
        $this->assertEqualsWithDelta(1000, $r['under_progress']['end_balance'][20], self::DELTA);   // still being installed and tested
        $this->assertEqualsWithDelta(0, $r['under_progress']['end_balance'][21], self::DELTA);
        $months = array_keys($r['depreciation']['total_monthly_depreciation']);
        $this->assertSame(22, min($months));
    }

    public function test_the_loan_interest_of_a_machine_is_added_to_its_cost_until_it_is_ready(): void
    {
        $r = $this->productionLine(['equity_rate' => 0, 'interest_rate' => 12, 'tenor' => 24, 'loan_grace' => 12]);

        $this->assertGreaterThan(1000, $r['transferred_amount']);
        $this->assertSame(21, $r['transfer_index']);
        foreach ($r['under_progress']['capitalized_interest'] as $month => $interest) {
            if ($month > 20) {
                $this->assertEqualsWithDelta(0, $interest, self::DELTA);   // no more interest is capitalised once it is ready
            }
        }
        $this->assertGreaterThan(0, array_sum($r['under_progress']['capitalized_interest']));
    }

    public function test_a_milestone_can_be_paid_some_days_later(): void
    {
        $r = $this->productionLine(['milestones' => ['purchase' => ['rate' => 50, 'days' => 0], 'delivery' => ['rate' => 30, 'days' => 30], 'ready' => ['rate' => 20, 'days' => 60]]]);

        $this->assertEqualsWithDelta(500, $r['payments'][12], self::DELTA);
        $this->assertEqualsWithDelta(300, $r['payments'][19], self::DELTA);   // a month after the delivery
        $this->assertEqualsWithDelta(200, $r['payments'][22], self::DELTA);   // two months after it is ready
    }

    public function test_a_machine_paid_in_cash_is_paid_at_the_order(): void
    {
        $r = $this->productionLine(['terms' => 'cash']);

        $this->assertEquals([12 => 1000.0], $r['payments']);
        $this->assertEqualsWithDelta(1000, $r['execution'][12], self::DELTA);   // paid in advance, so spent when paid
        $this->assertEqualsWithDelta(0, $r['payable'][18], self::DELTA);
    }

    public function test_a_machine_on_customised_terms_counts_its_days_from_the_purchase(): void
    {
        $r = $this->productionLine(['terms' => 'customize', 'policy' => [0 => 40, 60 => 60], 'from_total' => [0 => false, 60 => false]]);

        $this->assertEqualsWithDelta(400, $r['payments'][12], self::DELTA);
        $this->assertEqualsWithDelta(600, $r['payments'][14], self::DELTA);
    }

    public function test_a_machine_with_no_delay_is_transferred_in_the_month_it_is_bought(): void
    {
        $r = $this->calculate(['type' => 'machine', 'amount' => 1200, 'start' => 3, 'delivery' => 3, 'end' => 3, 'depreciation_years' => 1, 'terms' => 'cash']);

        $this->assertSame(3, $r['transfer_index']);
        $this->assertEquals([3 => 1200.0], $r['execution']);
        $this->assertEqualsWithDelta(100, $r['depreciation']['total_monthly_depreciation'][4], self::DELTA);
    }

    public function test_instalment_payments(): void
    {
        // 10 % reservation at month 0, 20 % two months later, the other 70 % in 4 monthly instalments after a 1 month grace.
        $paid = FixedAssetCalculator::installments(0, 1000, 10, 20, 2, 1, 4, 'monthly');

        $this->assertEqualsWithDelta(100, $paid[0], self::DELTA);
        $this->assertEqualsWithDelta(200, $paid[2], self::DELTA);
        foreach ([4, 5, 6, 7] as $month) {
            $this->assertEqualsWithDelta(175, $paid[$month], self::DELTA);
        }
        $this->assertEqualsWithDelta(1000, array_sum($paid), self::DELTA);

        // Contractual payment on the same day: the two down payments are one.
        $one = FixedAssetCalculator::installments(3, 1000, 10, 20, 0, 0, 1, 'quarterly');
        $this->assertEqualsWithDelta(300, $one[3], self::DELTA);
        $this->assertEqualsWithDelta(700, $one[6], self::DELTA);
    }

    public function test_equity_pays_first_then_the_loan(): void
    {
        // 4 payments of 100; equity covers 50 % of 400.
        $f = FixedAssetCalculator::funding([0 => 100.0, 1 => 100.0, 2 => 100.0, 3 => 100.0], 400, 50);

        $this->assertEquals([0 => 100.0, 1 => 100.0, 2 => 0.0, 3 => 0.0], $f['equity']);
        $this->assertEquals([2 => 100.0, 3 => 100.0], $f['withdrawal']);

        // The month the equity runs out only the part it could not cover is drawn.
        $g = FixedAssetCalculator::funding([0 => 100.0, 1 => 100.0, 2 => 100.0], 300, 50);
        $this->assertEquals([1 => 50.0, 2 => 100.0], $g['withdrawal']);

        // Fully funded by equity: no loan.
        $this->assertSame([], FixedAssetCalculator::funding([0 => 100.0, 1 => 100.0], 200, 100)['withdrawal']);
    }

    public function test_the_loan_without_interest_is_repaid_in_equal_instalments(): void
    {
        $s = LoanCalculator::schedule(0, '2026-01-01', 1200, 0, 12, 'monthly', 0);

        $this->assertCount(13, $s['beginning']);
        $this->assertEqualsWithDelta(0, $s['schedulePayment'][0], self::DELTA);
        foreach (range(1, 12) as $m) {
            $this->assertEqualsWithDelta(100, $s['schedulePayment'][$m], self::DELTA, "month $m");
        }
        $this->assertEqualsWithDelta(0, $s['endBalance'][12], self::DELTA);
    }

    public function test_the_loan_pays_only_interest_during_the_grace_period_and_ends_at_zero(): void
    {
        $s = LoanCalculator::schedule(0, '2026-01-01', 12000, 12, 12, 'monthly', 3);

        // January has 31 days: 12000 × 12 % / 360 × 31
        $this->assertEqualsWithDelta(0, $s['interestAmount'][0], self::DELTA);
        $this->assertEqualsWithDelta(12000 * 0.12 / 360 * 31, $s['interestAmount'][1], self::DELTA);
        // Grace months 1..3: the payment is only the interest, the balance does not move.
        foreach ([1, 2, 3] as $m) {
            $this->assertEqualsWithDelta($s['interestAmount'][$m], $s['schedulePayment'][$m], self::DELTA);
            $this->assertEqualsWithDelta(12000, $s['endBalance'][$m], self::DELTA);
        }
        // From month 4 equal instalments, the last one clears the loan.
        $this->assertEqualsWithDelta($s['schedulePayment'][4], $s['schedulePayment'][11], self::DELTA);
        $this->assertEqualsWithDelta(0, $s['endBalance'][12], 1.0);
        $this->assertEqualsWithDelta(12000, array_sum($s['principleAmount']), 1.0);
    }

    public function test_a_quarterly_loan_pays_every_three_months(): void
    {
        $s = LoanCalculator::schedule(2, '2026-03-01', 1000, 0, 12, 'quarterly', 0);

        $this->assertEquals([2, 5, 8, 11, 14], array_keys($s['beginning']));
        $this->assertEqualsWithDelta(250, $s['schedulePayment'][5], self::DELTA);
        $this->assertEqualsWithDelta(0, $s['endBalance'][14], self::DELTA);
    }

    public function test_drawdown_adds_interest_for_the_days_since_the_last_draw(): void
    {
        // 1000 drawn in January, 1000 more in February: the February balance (2000) earns 31 days of interest.
        $d = LoanCalculator::drawdown([0 => 1000.0, 1 => 1000.0], 12, '2026-01-01');

        $this->assertEqualsWithDelta(0, $d['interest'][0], self::DELTA);
        $this->assertEqualsWithDelta(2000 * 0.12 / 360 * 31, $d['interest'][1], self::DELTA);
        $this->assertEqualsWithDelta(2000 + 2000 * 0.12 / 360 * 31, $d['amount'], self::DELTA);
        $this->assertSame(1, $d['start_index']);
    }

    public function test_the_asset_is_depreciated_from_the_month_after_it_is_transferred(): void
    {
        // 1200 paid in month 0, operation starts in month 0: transferred in month 0, 24 months of 50 from month 1.
        $r = $this->calculate();

        $this->assertSame(0, $r['transfer_index']);
        $this->assertEqualsWithDelta(1200, $r['transferred_amount'], self::DELTA);
        $m = $r['depreciation']['total_monthly_depreciation'];
        $this->assertEqualsWithDelta(0, $m[0] ?? 0, self::DELTA);
        $this->assertEqualsWithDelta(50, $m[1], self::DELTA);
        $this->assertEqualsWithDelta(50, $m[24], self::DELTA);
        $this->assertEqualsWithDelta(0, $m[25] ?? 0, self::DELTA);
        $this->assertEqualsWithDelta(1200, $r['depreciation']['accumulated_depreciation'][30], self::DELTA);
        $this->assertEqualsWithDelta(0, $r['depreciation']['end_balance'][30], self::DELTA);
        $this->assertEqualsWithDelta(1200 - 50 * 10, $r['depreciation']['end_balance'][10], self::DELTA);
    }

    public function test_an_asset_finished_before_operation_is_depreciated_from_the_operation_start(): void
    {
        // Built in months 0..2, operation starts in month 6: transferred in month 3, depreciated from month 6.
        $r = $this->calculate(['start' => 0, 'end' => 2, 'depreciation_years' => 1], null, 6);

        $this->assertSame(3, $r['transfer_index']);
        $m = $r['depreciation']['total_monthly_depreciation'];
        $this->assertEqualsWithDelta(0, $m[5] ?? 0, self::DELTA);
        $this->assertEqualsWithDelta(100, $m[6], self::DELTA);
        $this->assertEqualsWithDelta(100, $m[17], self::DELTA);
        $this->assertEqualsWithDelta(0, $m[18] ?? 0, self::DELTA);
    }

    public function test_a_replacement_cost_comes_back_every_year_and_is_depreciated(): void
    {
        // 10 % replacement every year, depreciation 2 years: month 12 adds 10 % of the gross value.
        $r = $this->calculate(['replacement_rate' => 10, 'replacement_years' => 1]);

        $d = $r['depreciation'];
        $this->assertEqualsWithDelta(120, $d['replacement_cost'][12], self::DELTA);
        $this->assertEqualsWithDelta(1320, $d['final_total_gross'][12], self::DELTA);
        // From month 13 the replacement adds 120 / 24 = 5 a month on top of the 50.
        $this->assertEqualsWithDelta(55, $d['total_monthly_depreciation'][13], self::DELTA);
        $this->assertEqualsWithDelta(55, $d['total_monthly_depreciation'][24], self::DELTA);
        // At month 30 the first replacement (5 a month until month 36) and the second one, 10 % of 1320 at month 24 (5.5 a month), both run.
        $this->assertEqualsWithDelta(10.5, $d['total_monthly_depreciation'][30], self::DELTA);
    }

    public function test_no_depreciation_keeps_the_gross_value(): void
    {
        $r = $this->calculate(['depreciation_years' => 0]);

        $this->assertSame([], array_filter($r['depreciation']['total_monthly_depreciation']));
        $this->assertEqualsWithDelta(1200, $r['depreciation']['end_balance'][20], self::DELTA);
    }

    public function test_depreciation_is_split_between_manufacturing_and_administration(): void
    {
        $r = $this->calculate(['admin_pct' => 20, 'manufacturing_pct' => 80]);

        $this->assertEqualsWithDelta(40, $r['manufacturing_depreciation'][1], self::DELTA);
        $this->assertEqualsWithDelta(10, $r['administration_depreciation'][1], self::DELTA);
    }

    public function test_loan_interest_up_to_the_transfer_is_added_to_the_cost(): void
    {
        // 1200 spent over months 0..3 (300 a month), equity 50 %: the loan pays from month 2. Operation starts in month 6.
        $r = $this->calculate(['start' => 0, 'end' => 3, 'equity_rate' => 50, 'interest_rate' => 12, 'tenor' => 24, 'loan_grace' => 12], null, 6);

        $this->assertEqualsWithDelta(600, array_sum($r['loan_withdrawal']), self::DELTA);
        $this->assertGreaterThan(0, array_sum($r['loan_withdrawal_interest']));
        $this->assertEqualsWithDelta(1200 + array_sum($r['under_progress']['capitalized_interest']), $r['transferred_amount'], self::DELTA);
        $this->assertGreaterThan(1200, $r['transferred_amount']);
        // The loan starts in the last month it was drawn, with the interest so far added.
        $this->assertSame(3, $r['loan_start_index']);
        $this->assertEqualsWithDelta($r['loan_withdrawal_end_balance'][3], $r['loan_amount'], self::DELTA);
        $this->assertEqualsWithDelta($r['loan_amount'], $r['schedule']['beginning'][3], self::DELTA);
    }

    public function test_the_payable_is_what_was_executed_minus_what_was_paid(): void
    {
        // 100 executed in month 0, paid in month 2.
        $p = FixedAssetCalculator::payable([0 => 100.0], [2 => 100.0], 5);

        $this->assertEquals([0 => 100.0, 1 => 100.0, 2 => 0.0, 3 => 0.0, 4 => 0.0, 5 => 0.0], $p);
        // An advance payment shows as a negative payable.
        $advance = FixedAssetCalculator::payable([1 => 40.0], [0 => 100.0], 3);
        $this->assertEqualsWithDelta(-100, $advance[0], self::DELTA);
        $this->assertEqualsWithDelta(-60, $advance[1], self::DELTA);
    }

    public function test_money_due_after_the_last_month_of_the_study_is_not_counted(): void
    {
        $r = $this->calculate(['start' => 34, 'end' => 35, 'terms' => 'customize', 'policy' => [0 => 50, 90 => 50], 'from_total' => [0 => false, 90 => false]], $this->timeline('2026-01-01', 1), 0, 11);
        $last = max(array_keys($this->timeline('2026-01-01', 1)->dates));

        $this->assertLessThanOrEqual($last, max(array_keys($r['payments'])));
    }

    // ── Part 2: the results the previous ZAVERO saved ────────────────────────

    private function golden(): array
    {
        return json_decode(file_get_contents(dirname(__DIR__, 2).'/Fixtures/stage4_golden.json'), true);
    }

    public function test_the_fixture_is_there(): void
    {
        $this->assertCount(8, $this->golden()['cases']);
    }

    public function test_fixed_assets_match_the_old_results(): void
    {
        foreach ($this->golden()['cases'] as $case) {
            $label = $case['label'];
            $tl = Timeline::forStudy($case['study']['start'], $case['study']['duration'], $case['study']['operation_start']);
            $operation = array_key_first($tl->operationMonths);
            $studyEnd = array_search($case['study']['end'], $tl->dates, true);

            $r = FixedAssetCalculator::calculate($case['inputs'], $tl, $operation, $studyEnd);
            $e = $case['expected'];

            $this->assertSeries($e['payments'], $r['payments'], "$label payments", true);
            $this->assertSeries($e['equity'], $r['equity_payment'], "$label equity payment", true);
            if ($e['withdrawal'] !== []) {
                $this->assertSeries($e['withdrawal'], $r['loan_withdrawal'], "$label loan withdrawal", true);
                $this->assertSeries($e['withdrawal_interest'], $r['loan_withdrawal_interest'], "$label drawdown interest", true);
            }
            $this->assertSeries($e['cap_total'], $r['under_progress']['total'], "$label capitalisation total", true);
            $this->assertSeries($e['cap_transferred'], $r['under_progress']['transferred_date_and_vales'], "$label transferred");
            $this->assertSeries($e['gross'], $r['depreciation']['final_total_gross'], "$label gross value", true);
            $this->assertSeries($e['end_balance'], $r['depreciation']['end_balance'], "$label net book value", true);
            $this->assertSeries($e['replacement'], $r['depreciation']['replacement_cost'], "$label replacement", true);
            if ($e['monthly_depreciation'] !== []) {
                $this->assertSeries($e['monthly_depreciation'], $r['depreciation']['total_monthly_depreciation'], "$label depreciation", true);
                $this->assertSeries($e['admin'], $r['administration_depreciation'], "$label administration depreciation", true);
            }
            // The old application listed the payable only from the first to the last payment month.
            $this->assertSeries($e['payable'], array_intersect_key($r['payable'], $e['payable']), "$label payable");

            if (isset($e['loan'])) {
                $this->assertSame($e['loan']['start'], $r['loan_start_index'], "$label loan start");
                foreach (['beginning', 'interestAmount', 'schedulePayment', 'principleAmount'] as $column) {
                    $this->assertSeries($e['loan'][$column], $r['schedule'][$column], "$label loan $column", true);
                }
                $this->assertSeries($e['loan']['endBalance'], $r['schedule_end_balance'], "$label loan end balance");
            }
        }
    }
}
