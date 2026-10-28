<?php

namespace Tests\Unit\Calculations;

use App\Calculations\SeasonalityCalculator;
use App\Calculations\Timeline;
use PHPUnit\Framework\TestCase;

// The months a calculation runs over, and how a year is spread over its months.
// Run:  php artisan test --filter=TimelineAndSeasonalityTest
class TimelineAndSeasonalityTest extends TestCase
{
    public function test_a_one_year_study_runs_two_calendar_years(): void
    {
        // 2026-01 .. 2027-12: the study year plus the extended end year the old application also kept.
        $timeline = Timeline::forStudy('2026-01-01', 1, '2026-01-01');

        $this->assertCount(24, $timeline->operationMonths);
        $this->assertSame('2026-01-01', $timeline->operationMonths[0]);
        $this->assertSame('2027-12-01', $timeline->operationMonths[23]);
        $this->assertSame(0, $timeline->yearIndexOf(11));
        $this->assertSame(1, $timeline->yearIndexOf(12));
        $this->assertSame('03', $timeline->monthNumberOf(2));
    }

    public function test_a_five_year_study_has_72_months_and_operation_can_start_later(): void
    {
        $this->assertCount(72, Timeline::forStudy('2025-01-01', 5, '2025-01-01')->operationMonths);

        $late = Timeline::forStudy('2026-01-01', 5, '2026-04-01');
        $this->assertSame(3, array_key_first($late->operationMonths));
        $this->assertSame([3, 4, 5, 6, 7, 8, 9, 10, 11], $late->firstOperationYearMonths());
    }

    public function test_the_months_are_grouped_by_year(): void
    {
        $years = Timeline::forStudy('2025-01-01', 2, '2025-01-01')->operationMonthsByYear();

        $this->assertSame([0, 1, 2], array_keys($years));
        $this->assertCount(12, $years[0]);
        $this->assertCount(12, $years[2]);
    }

    public function test_a_flat_year_gives_every_month_the_same_share(): void
    {
        $timeline = Timeline::forStudy('2026-01-01', 1, '2026-01-01');
        $shares = SeasonalityCalculator::monthlyShares(SeasonalityCalculator::monthWeights('flat'), $timeline, 0);

        $this->assertEqualsWithDelta(1 / 12, $shares[0], 1e-12);
        $this->assertEqualsWithDelta(1.0, array_sum(array_slice($shares, 0, 12)), 1e-12);
        $this->assertEqualsWithDelta(1.0, array_sum(array_slice($shares, 12, 12)), 1e-12);
    }

    public function test_months_before_the_selling_start_get_nothing_and_the_rest_share_the_year(): void
    {
        $timeline = Timeline::forStudy('2026-01-01', 1, '2026-01-01');
        $shares = SeasonalityCalculator::monthlyShares(SeasonalityCalculator::monthWeights('flat'), $timeline, 3);

        $this->assertSame(0.0, (float) $shares[0]);
        $this->assertSame(0.0, (float) $shares[2]);
        $this->assertEqualsWithDelta(1 / 9, $shares[3], 1e-12);
        $this->assertEqualsWithDelta(1.0, array_sum(array_slice($shares, 0, 12)), 1e-12);
    }

    public function test_quarterly_percentages_become_month_weights(): void
    {
        $weights = SeasonalityCalculator::monthWeights('quarterly', [20, 40, 40, 0]);

        $this->assertEqualsWithDelta(0.2 / 3, $weights['01'], 1e-12);
        $this->assertEqualsWithDelta(0.4 / 3, $weights['05'], 1e-12);
        $this->assertSame(0.0, $weights['12']);
        $this->assertEqualsWithDelta(1.0, array_sum($weights), 1e-12);
    }

    public function test_monthly_percentages_leave_a_zero_month_without_sales(): void
    {
        $timeline = Timeline::forStudy('2026-01-01', 1, '2026-01-01');
        $weights = SeasonalityCalculator::monthWeights('monthly', [10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 0, 0]);
        $shares = SeasonalityCalculator::monthlyShares($weights, $timeline, 0);

        $this->assertEqualsWithDelta(0.1, $shares[0], 1e-12);
        $this->assertSame(0.0, (float) $shares[10]);
        $this->assertSame(0.0, (float) $shares[11]);
    }
}
