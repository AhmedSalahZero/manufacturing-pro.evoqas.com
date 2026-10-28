<?php

namespace App\Excel;

use App\Support\StudyCalendar;

// What the workbook is built from: a study's inputs in the shape of the saved rows (see Console/Commands/ExportModelDump.php),
// plus the calendar facts every sheet needs (months of the grid, years, study end, ...).
final class ModelData
{
    public readonly array $p;
    public readonly string $sourceNote;
    public readonly bool $new;
    /** @var list<string> "Y-m-d" of every month of the grid (index 0 = the study's first month) */
    public readonly array $dates;
    public readonly int $D;           // months in the grid
    public readonly int $S;           // months of the study
    public readonly int $baseYear;
    public readonly int $startMonth;  // 1..12
    public readonly int $endYear;
    public readonly int $Y;           // year columns (study years + the extended year)
    public readonly int $opIdx;
    public readonly int $studyEndIdx; // index of December of the end year
    public readonly int $extEndIdx;   // index of December of the extended end year
    public readonly array $products;
    public readonly array $rms;
    public readonly array $staff;
    public readonly array $expenses;
    public readonly array $assets;
    public readonly array $opening;

    /** @param array<string,mixed> $dump */
    public function __construct(array $dump)
    {
        $p = $dump['project'];
        $this->p = $p;
        $this->sourceNote = (string) ($dump['source_note'] ?? '');
        $this->new = (bool) $p['new_company'];
        // the first study month as the application itself stored it (a plain day), so no time zone can move it; the start date is the fallback
        $start = substr((string) (($p['study_dates'][0] ?? null) ?: $p['start_date']), 0, 10);
        $first = StudyCalendar::build($start, (int) $p['duration'], $start);
        $this->dates = $first['study_dates'];
        $this->D = count($this->dates);
        $this->S = (int) $p['duration'] * 12;
        $this->baseYear = (int) substr($this->dates[0], 0, 4);
        $this->startMonth = (int) substr($this->dates[0], 5, 2);
        $this->endYear = (int) substr($first['end_date'], 0, 4);
        $this->Y = $this->endYear + 1 - $this->baseYear + 1;
        $this->opIdx = (int) $p['operation_start_date'];
        $this->studyEndIdx = StudyCalendar::indexOf($this->dates, $first['end_date']);
        $this->extEndIdx = StudyCalendar::indexOf($this->dates, $first['extended_end_date']);
        $this->products = array_values($dump['products'] ?? []);
        $this->rms = array_values($dump['raw_materials'] ?? []);
        $this->staff = array_values($dump['manpowers'] ?? []);
        $this->expenses = array_values($dump['expenses'] ?? []);
        $this->assets = array_values($dump['fixed_assets'] ?? []);
        $this->opening = $dump['opening'] ?? [];
    }

    /** Excel serial number of the first of a grid month. */
    public function serial(int $idx): int
    {
        return self::serialOf($this->dates[$idx] ?? $this->dates[array_key_last($this->dates)]);
    }

    public static function serialOf(string $ymd): int
    {
        // A calendar day is the same day everywhere, so it is read as midnight UTC. In the server's own zone (Cairo, 2 hours ahead
        // of UTC) midnight is 22:00 of the day before in UTC, and the day count came out one day short, so the workbook started
        // one month early. 25569 = the Excel number of 1970-01-01.
        return intdiv((new \DateTimeImmutable(substr($ymd, 0, 10), new \DateTimeZone('UTC')))->getTimestamp(), 86400) + 25569;
    }

    /** A value of a year-indexed input (year index => value) for every year column. @return list<float> */
    public function yearly(mixed $values): array
    {
        $values = (array) $values;
        $out = [];
        for ($y = 0; $y < $this->Y; $y++) {
            $out[] = (float) ($values[$y] ?? 0);
        }

        return $out;
    }

    /** @return array<int|string,mixed> */
    public static function arr(mixed $v): array
    {
        if (is_array($v)) {
            return $v;
        }
        if (is_string($v) && $v !== '') {
            $d = json_decode($v, true);

            return is_array($d) ? $d : [];
        }

        return [];
    }
}
