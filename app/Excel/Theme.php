<?php

namespace App\Excel;

// The look of the workbook: ZAVERO navy / blue, input cells in the same cream-and-blue as the application's forms.
final class Theme
{
    public const NAVY = 'FF0E2350';
    public const BLUE = 'FF2158D6';
    public const BLUE_SOFT = 'FFE8EEFC';
    public const INPUT_FILL = 'FFFFF6D4';
    public const INPUT_INK = 'FF1B3DB0';
    public const INPUT_LINE = 'FFE3C968';
    public const MUTE = 'FF556178';
    public const GREEN_SOFT = 'FFE3F5EA';
    public const AMBER_SOFT = 'FFFFF3D6';

    public const NUM = '#,##0;-#,##0;"-"';
    public const NUM2 = '#,##0.00;-#,##0.00;"-"';
    public const PCT = '0.##"%"';
    public const PCTF = '0.0%';
    public const DATE = 'yyyy-mm';

    /** @var array<string,int> */
    private array $cache = [];

    public function __construct(private readonly Styles $styles)
    {
    }

    public function s(string $name): int
    {
        return $this->cache[$name] ??= $this->styles->id($this->spec($name));
    }

    /** @return array<string,mixed> */
    private function spec(string $name): array
    {
        $input = ['locked' => false, 'fill' => self::INPUT_FILL, 'font' => ['color' => self::INPUT_INK], 'border' => 'thin', 'border_color' => self::INPUT_LINE];
        $head = ['font' => ['b' => true, 'color' => self::NAVY], 'fill' => self::BLUE_SOFT, 'border' => 'bottom', 'border_color' => 'FF9DB4E8', 'h' => 'center', 'v' => 'center', 'wrap' => true];

        return match ($name) {
            'title' => ['font' => ['b' => true, 'sz' => 16, 'color' => 'FFFFFFFF'], 'fill' => self::NAVY, 'v' => 'center'],
            'titlefill' => ['fill' => self::NAVY],
            'subtitle' => ['font' => ['i' => true, 'sz' => 10, 'color' => self::MUTE]],
            'section' => ['font' => ['b' => true, 'sz' => 11, 'color' => 'FFFFFFFF'], 'fill' => self::BLUE, 'v' => 'center'],
            'hdr' => $head,
            'hdr_l' => ['h' => 'left'] + $head,
            'hdr_date' => ['fmt' => self::DATE] + $head,
            'hdr_int' => ['fmt' => '0'] + $head,
            'hdr_small' => ['font' => ['sz' => 9, 'color' => self::MUTE]] + ['h' => 'center', 'fill' => self::BLUE_SOFT],
            'label' => ['v' => 'center'],
            'label_b' => ['font' => ['b' => true], 'v' => 'center'],
            'label_i' => ['indent' => 1, 'v' => 'center'],
            'label_t' => ['font' => ['b' => true], 'border' => 'top', 'border_color' => 'FF7A8599'],
            'note' => ['font' => ['i' => true, 'sz' => 9, 'color' => self::MUTE], 'v' => 'center'],
            'wrap' => ['wrap' => true, 'v' => 'top'],
            'num' => ['fmt' => self::NUM],
            'num2' => ['fmt' => self::NUM2],
            'int' => ['fmt' => '0'],
            'pct' => ['fmt' => self::PCT],
            'pctf' => ['fmt' => self::PCTF],
            'date' => ['fmt' => self::DATE, 'h' => 'center'],
            'text' => [],
            'flag' => ['fmt' => '0', 'font' => ['sz' => 9, 'color' => 'FF7A8599'], 'h' => 'center'],
            'tot' => ['font' => ['b' => true], 'fmt' => self::NUM, 'border' => 'top', 'border_color' => 'FF7A8599'],
            'tot2' => ['font' => ['b' => true], 'fmt' => self::NUM2, 'border' => 'top', 'border_color' => 'FF7A8599'],
            'bold' => ['font' => ['b' => true], 'fmt' => self::NUM],
            'sumcol' => ['font' => ['b' => true, 'color' => self::NAVY], 'fmt' => self::NUM, 'fill' => 'FFF3F5F9'],
            'in_num' => ['fmt' => self::NUM2] + $input,
            'in_int' => ['fmt' => '#,##0'] + $input,
            'in_pct' => ['fmt' => self::PCT] + $input,
            'in_txt' => ['h' => 'left'] + $input,
            'in_date' => ['fmt' => self::DATE, 'h' => 'center'] + $input,
            'in_list' => ['h' => 'center'] + $input,
            'lock_txt' => ['fill' => 'FFF3F5F9', 'font' => ['color' => self::MUTE], 'h' => 'left'],
            'lock_date' => ['fill' => 'FFF3F5F9', 'font' => ['color' => self::MUTE], 'fmt' => self::DATE, 'h' => 'center'],
            'lock_int' => ['fill' => 'FFF3F5F9', 'font' => ['color' => self::MUTE], 'fmt' => '0', 'h' => 'center'],
            'link' => ['font' => ['color' => self::BLUE, 'b' => true], 'v' => 'center'],
            'ok' => ['font' => ['b' => true], 'h' => 'center', 'fmt' => self::NUM2],
            'kpi' => ['font' => ['b' => true, 'sz' => 14, 'color' => self::NAVY], 'fmt' => self::NUM, 'h' => 'center', 'fill' => self::BLUE_SOFT],
            'kpi_pct' => ['font' => ['b' => true, 'sz' => 14, 'color' => self::NAVY], 'fmt' => '0.0"%"', 'h' => 'center', 'fill' => self::BLUE_SOFT],
            'kpi_lbl' => ['font' => ['sz' => 9, 'color' => self::MUTE], 'h' => 'center', 'fill' => self::BLUE_SOFT, 'wrap' => true],
            'logo' => ['fill' => 'FFFFFFFF', 'border' => 'thin', 'border_color' => 'FFBFBFBF', 'font' => ['i' => true, 'sz' => 9, 'color' => 'FF7A8599'], 'h' => 'center', 'v' => 'center', 'wrap' => true],
            'brand' => ['font' => ['b' => true, 'sz' => 26, 'color' => self::NAVY]],
            'brand_sub' => ['font' => ['sz' => 12, 'color' => self::BLUE]],
            'legend_in' => ['fmt' => '0'] + $input,
            default => throw new \InvalidArgumentException("Unknown style '$name'"),
        };
    }
}
