<?php

namespace Tests\Unit;

use App\Support\StudyCalendar;
use PHPUnit\Framework\TestCase;

// How many calendar years a study's yearly figures (FX forecast, channel allocations ...) cover.
// Run:  php artisan test --filter=StudyCalendarYearCountTest
class StudyCalendarYearCountTest extends TestCase
{
    public function test_a_study_starting_in_january_covers_exactly_its_duration(): void
    {
        $this->assertSame(1, StudyCalendar::yearCount('2026-01-01', 1));
        $this->assertSame(3, StudyCalendar::yearCount('2026-01-01', 3));
        $this->assertSame(7, StudyCalendar::yearCount('2026-01-01', 7));
    }

    public function test_a_study_starting_mid_year_reaches_one_more_calendar_year(): void
    {
        // March 2026 + 5 years - 1 month = February 2031: the years 2026 to 2031.
        $this->assertSame(6, StudyCalendar::yearCount('2026-03-01', 5));
        $this->assertSame(2, StudyCalendar::yearCount('2026-12-01', 1));
    }

    public function test_it_matches_the_year_range_of_the_product_sales_tables(): void
    {
        // The product form shows year index 0 up to (end year - start year); the count is that range's length.
        $built = StudyCalendar::build('2026-03-01', 5, '2026-03-01');
        $this->assertSame((int) substr($built['end_date'], 0, 4) - 2026 + 1, StudyCalendar::yearCount('2026-03-01', 5));
    }
}
