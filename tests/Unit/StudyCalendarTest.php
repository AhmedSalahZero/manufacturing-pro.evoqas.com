<?php

namespace Tests\Unit;

use App\Support\StudyCalendar;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

// The study calendar must give exactly the dates the previous ZAVERO saved.
// The golden file holds 9 real studies from the old database. Run:  php artisan test --filter=StudyCalendarTest
class StudyCalendarTest extends TestCase
{
    public function test_every_golden_case_matches_the_old_application(): void
    {
        $cases = json_decode(file_get_contents(__DIR__.'/../Fixtures/study_calendar_golden.json'), true);
        $this->assertNotEmpty($cases);

        foreach ($cases as $i => $c) {
            $r = StudyCalendar::build($c['start_date'], $c['duration'], $c['operation_start_date']);

            $this->assertSame($c['end_date'], $r['end_date'], "case $i end_date");
            $this->assertSame($c['extended_end_date'], $r['extended_end_date'], "case $i extended_end_date");
            $this->assertSame($c['study_dates'], array_values($r['study_dates']), "case $i study_dates");
            $this->assertEquals($c['operation_dates'], $r['operation_dates'], "case $i operation_dates");
        }
    }

    public function test_a_one_year_study_starting_in_march_ends_in_december_of_the_next_year(): void
    {
        // March 2026 + 1 year - 1 month = February 2027, so the study ends in December 2027 (as the old application did).
        $r = StudyCalendar::build('2026-03-01', 1, '2026-03-01');

        $this->assertSame('2027-12-01', $r['end_date']);
    }

    public function test_a_one_year_study_starting_in_january_ends_in_december_of_the_same_year(): void
    {
        $r = StudyCalendar::build('2026-01-01', 1, '2026-01-01');

        $this->assertSame('2026-12-01', $r['end_date']);
    }

    public function test_a_five_year_study_starting_in_january_ends_in_december_of_the_fifth_year(): void
    {
        $r = StudyCalendar::build('2026-01-01', 5, '2026-01-01');

        $this->assertSame('2030-12-01', $r['end_date']);
    }

    public function test_the_months_are_padded_to_seven_calendar_years(): void
    {
        $r = StudyCalendar::build('2026-01-01', 2, '2026-01-01');

        $this->assertCount(84, $r['extended_study_dates']);
        $this->assertSame('2026-01-01', $r['extended_study_dates'][0]);
    }

    public function test_index_and_month_are_the_inverse_of_each_other(): void
    {
        $dates = StudyCalendar::build('2026-01-01', 3, '2026-01-01')['extended_study_dates'];

        $this->assertSame(5, StudyCalendar::indexOf($dates, '2026-06'));
        $this->assertSame('2026-06', StudyCalendar::monthAt($dates, 5));
        $this->assertNull(StudyCalendar::indexOf($dates, '1999-01'));
        $this->assertNull(StudyCalendar::monthAt($dates, null));
    }

    public function test_a_wrong_duration_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        StudyCalendar::build('2026-01-01', 8, '2026-01-01');
    }

    public function test_an_operation_start_before_the_study_start_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        StudyCalendar::build('2026-06-01', 3, '2026-01-01');
    }

    public function test_end_year_counts_from_the_month_before_the_end(): void
    {
        $this->assertSame(2030, StudyCalendar::endYear(new DateTimeImmutable('2026-01-01'), 5));
        $this->assertSame(2031, StudyCalendar::endYear(new DateTimeImmutable('2026-03-01'), 5));
    }
}
