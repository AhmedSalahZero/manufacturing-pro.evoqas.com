<?php

namespace App\Support;

use DateTimeImmutable;
use InvalidArgumentException;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — StudyCalendar (the months of a study)
//  Location: app/Support/StudyCalendar.php
//
//  Pure calculation: no database, no screens. It rebuilds, rule for rule, the dates the previous
//  ZAVERO stored on every project. Checked against 36 saved projects (tests/Fixtures/study_calendar_golden.json).
//
//  The rules
//    · A study starts on the first day of its start month and lasts N whole years (1 to 7).
//    · Its end date is December 1st of the year in which (start + N years - 1 month) falls.
//    · The "extended" end date is December 1st of the following year (one more year for the terminal value).
//    · Every month from the start month up to December of the last year is listed, and the list is padded
//      to at least 7 calendar years from the start year, so the screens always have the same room to grow.
//    · A month's "index" is its position in that list (0 = the start month). Monthly series elsewhere in the
//      application are stored by this index.
//    · Operation dates: the months from the operation start month to the end of the list, keyed by their
//      index in the study list.
// ══════════════════════════════════════════════════════════════════

final class StudyCalendar
{
    public const MIN_YEARS = 1;

    public const MAX_YEARS = 7;

    /**
     * @param  string  $startDate           study start, any day of the month, "Y-m-d"
     * @param  int     $durationYears       1 to 7
     * @param  string  $operationStartDate  operation start, "Y-m-d", not before the study start
     * @return array{end_date:string, extended_end_date:string, study_dates:list<string>, extended_study_dates:list<string>, operation_dates:array<int,string>}
     */
    public static function build(string $startDate, int $durationYears, string $operationStartDate): array
    {
        if ($durationYears < self::MIN_YEARS || $durationYears > self::MAX_YEARS) {
            throw new InvalidArgumentException('The duration must be between 1 and 7 years.');
        }

        $start = self::firstOfMonth($startDate);
        $operation = self::firstOfMonth($operationStartDate);

        if ($operation < $start) {
            throw new InvalidArgumentException('The operation cannot start before the study.');
        }

        $endYear = self::endYear($start, $durationYears);
        $lastYear = max($endYear + 1, (int) $start->format('Y') + self::MAX_YEARS - 1);

        $study = self::months($start, $lastYear);
        $firstOperation = $operation->format('Y-m-d');
        $firstIndex = array_search($firstOperation, $study, true);
        $operationDates = [];

        foreach (array_slice($study, (int) $firstIndex) as $offset => $date) {
            $operationDates[(int) $firstIndex + $offset] = $date;
        }

        return [
            'end_date'             => sprintf('%04d-12-01', $endYear),
            'extended_end_date'    => sprintf('%04d-12-01', $endYear + 1),
            'study_dates'          => $study,
            'extended_study_dates' => $study,
            'operation_dates'      => $operationDates,
        ];
    }

    /** The year of the last study month: start + N years - 1 month. */
    public static function endYear(DateTimeImmutable $start, int $durationYears): int
    {
        return (int) $start->modify('+'.$durationYears.' years')->modify('-1 month')->format('Y');
    }

    /** Position of a "Y-m" (or "Y-m-d") month in a list of study dates, or null when it is not in the list. */
    public static function indexOf(array $studyDates, string $month): ?int
    {
        $key = self::firstOfMonth(strlen($month) === 7 ? $month.'-01' : $month)->format('Y-m-d');
        $found = array_search($key, $studyDates, true);

        return $found === false ? null : (int) $found;
    }

    /** "Y-m" of the month at a position in a list of study dates, or null. */
    public static function monthAt(array $studyDates, ?int $index): ?string
    {
        return isset($studyDates[$index]) ? substr($studyDates[$index], 0, 7) : null;
    }

    private static function firstOfMonth(string $date): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', substr($date, 0, 10));

        if ($parsed === false) {
            throw new InvalidArgumentException("'{$date}' is not a date (Y-m-d).");
        }

        return $parsed->modify('first day of this month');
    }

    /** @return list<string> every month from $start to December of $lastYear, as "Y-m-01" */
    private static function months(DateTimeImmutable $start, int $lastYear): array
    {
        $months = [];
        $month = $start;
        $last = new DateTimeImmutable(sprintf('%04d-12-01', $lastYear));

        while ($month <= $last) {
            $months[] = $month->format('Y-m-d');
            $month = $month->modify('first day of next month');
        }

        return $months;
    }
}
