<?php

namespace App\Calculations;

// Adds a monthly series up per calendar quarter / half year / year (the January-December financial year the old
// application always used). The total sits at the LAST month of its period (March, June, September, December).
final class IntervalSums
{
    public const INTERVALS = ['monthly', 'quarterly', 'semi-annually', 'annually'];

    private const MONTHS_PER_PERIOD = ['monthly' => 1, 'quarterly' => 3, 'semi-annually' => 6, 'annually' => 12];

    /**
     * @param  array<int,float|int>  $series  month index => value
     * @return array<int,float|int>           month index of the period's last month => total
     */
    public static function sum(array $series, string $interval, Timeline $timeline): array
    {
        $size = self::MONTHS_PER_PERIOD[$interval];
        $monthIndexOfLabel = [];

        foreach ($timeline->dates as $index => $date) {
            $monthIndexOfLabel[substr($date, 0, 7)] = $index;
        }

        $result = [];

        foreach ($series as $index => $value) {
            if (! isset($timeline->dates[$index])) {
                continue;
            }
            $year = substr($timeline->dates[$index], 0, 4);
            $month = (int) $timeline->monthNumberOf($index);
            $lastMonth = (int) (ceil($month / $size) * $size);
            $target = $monthIndexOfLabel[sprintf('%s-%02d', $year, $lastMonth)] ?? null;

            if ($target === null) {
                continue;
            }
            $result[$target] = ($result[$target] ?? 0) + $value;
        }

        return $result;
    }

    /**
     * The months of the timeline from $from to $to (both included) that close a period of the interval:
     * every month for "monthly", March / June / September / December for "quarterly", and so on.
     *
     * @return list<int>
     */
    public static function periodEnds(string $interval, int $from, int $to, Timeline $timeline): array
    {
        $size = self::MONTHS_PER_PERIOD[$interval];
        $ends = [];

        foreach (array_keys($timeline->dates) as $index) {
            if ($index >= $from && $index <= $to && (int) $timeline->monthNumberOf($index) % $size === 0) {
                $ends[] = $index;
            }
        }

        return $ends;
    }
}
