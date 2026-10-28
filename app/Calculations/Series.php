<?php

namespace App\Calculations;

// Small helpers for monthly series (month index => number).
final class Series
{
    /** @param list<array<int,float|int>> $series @param list<int>|null $keys */
    public static function sum(array $series, ?array $keys = null): array
    {
        $keys ??= $series === [] ? [] : array_keys($series[0]);
        $total = [];

        foreach ($keys as $key) {
            $value = 0;
            foreach ($series as $one) {
                $value += $one[$key] ?? 0;
            }
            $total[$key] = $value;
        }

        return $total;
    }

    public static function scale(array $series, float|int $factor): array
    {
        return array_map(fn ($v) => $v * $factor, $series);
    }

    /** first minus all the others, at the given keys */
    public static function subtract(array $first, array $others, array $keys): array
    {
        $result = [];

        foreach ($keys as $key) {
            $value = $first[$key] ?? 0;
            foreach ($others as $other) {
                $value -= $other[$key] ?? 0;
            }
            $result[$key] = $value;
        }

        return $result;
    }

    public static function from(array $series, int $index): array
    {
        return array_filter($series, fn ($key) => $key >= $index, ARRAY_FILTER_USE_KEY);
    }
}
