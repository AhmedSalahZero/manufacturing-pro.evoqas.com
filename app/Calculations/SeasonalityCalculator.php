<?php

namespace App\Calculations;

// ══════════════════════════════════════════════════════════════════
//  Seasonality: how a year's sales are spread over its months.
//  Same rules as the old application:
//    · the type (flat / quarterly / monthly) is turned into a weight for each calendar month;
//    · months before the product's selling start get weight 0;
//    · inside every year the weights are divided by that year's total, so each year adds up to exactly 100 %.
// ══════════════════════════════════════════════════════════════════

final class SeasonalityCalculator
{
    public const TYPES = ['flat', 'quarterly', 'monthly'];

    /**
     * Weight of each calendar month ("01".."12") for the stored seasonality.
     *
     * @param  list<float|int|string|null>  $percentages  quarterly: 4 values; monthly: 12 values; flat: ignored
     * @return array<string,float>
     */
    public static function monthWeights(string $type, array $percentages = []): array
    {
        $weights = [];

        for ($month = 1; $month <= 12; $month++) {
            $key = sprintf('%02d', $month);

            $weights[$key] = match ($type) {
                'quarterly' => ((float) ($percentages[intdiv($month - 1, 3)] ?? 0)) / 3 / 100,
                'monthly'   => ((float) ($percentages[$month - 1] ?? 0)) / 100,
                default     => 1 / 12,
            };
        }

        return $weights;
    }

    /**
     * Share of the year's quantity that falls in each operation month.
     *
     * @param  array<string,float>  $weights        from monthWeights()
     * @return array<int,float>                     month index => share (0..1)
     */
    public static function monthlyShares(array $weights, Timeline $timeline, int $sellingStartIndex): array
    {
        $shares = [];

        foreach ($timeline->operationMonthsByYear() as $months) {
            $raw = [];
            foreach (array_keys($months) as $index) {
                $raw[$index] = $index >= $sellingStartIndex ? ($weights[$timeline->monthNumberOf($index)] ?? 0) : 0;
            }
            $total = array_sum($raw);

            foreach ($raw as $index => $value) {
                $shares[$index] = $total ? $value / $total : 0;
            }
        }

        return $shares;
    }
}
