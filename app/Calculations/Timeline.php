<?php

namespace App\Calculations;

use App\Support\StudyCalendar;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — Timeline (the months a calculation runs over)
//  Location: app/Calculations/Timeline.php
//
//  Pure calculation: wraps the StudyCalendar output.
//    · dates:            month index => "Y-m-01"  (every month of the study list)
//    · operationMonths:  month index => "Y-m-01"  (from the operation start to December of the extended end year;
//                        this is the span the old application kept every monthly series for)
//    · year index:       calendar year of the month minus the calendar year of the first study month
// ══════════════════════════════════════════════════════════════════

final class Timeline
{
    /** @var array<int,string> */
    public array $dates;

    /** @var array<int,string> */
    public array $operationMonths;

    private int $baseYear;

    /** @param array<int,string> $dates @param array<int,string> $operationMonths */
    public function __construct(array $dates, array $operationMonths)
    {
        $this->dates = $dates;
        $this->operationMonths = $operationMonths;
        $this->baseYear = (int) substr((string) reset($dates), 0, 4);
    }

    public static function forStudy(string $startDate, int $durationYears, string $operationStartDate): self
    {
        $calendar = StudyCalendar::build($startDate, $durationYears, $operationStartDate);
        $last = $calendar['extended_end_date'];
        $months = [];

        foreach ($calendar['operation_dates'] as $index => $date) {
            if ($date <= $last) {
                $months[$index] = $date;
            }
        }

        return new self($calendar['study_dates'], $months);
    }

    public function yearIndexOf(int $monthIndex): int
    {
        return (int) substr($this->dates[$monthIndex], 0, 4) - $this->baseYear;
    }

    /** "01".."12" */
    public function monthNumberOf(int $monthIndex): string
    {
        return substr($this->dates[$monthIndex], 5, 2);
    }

    /** @return array<int,array<int,int>> year index => [month index => 1] for the operation months */
    public function operationMonthsByYear(): array
    {
        $years = [];

        foreach (array_keys($this->operationMonths) as $index) {
            $years[$this->yearIndexOf($index)][$index] = 1;
        }

        return $years;
    }

    /** @return list<int> month indexes of the first operation year */
    public function firstOperationYearMonths(): array
    {
        $years = $this->operationMonthsByYear();

        return $years === [] ? [] : array_keys($years[array_key_first($years)]);
    }
}
