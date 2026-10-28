<?php

namespace App\Excel;

// Everything the sheet builders share: the workbook, the theme, the labels, the model, the sheets and the place of every named row.
final class Ctx
{
    /** First month column (A label, B unit / note, C total, D the "before the first month" column, E month 0). */
    public const FIRST = 5;

    public readonly Workbook $wb;
    public readonly Theme $th;
    public readonly bool $rtl;

    /** @var array<string,Sheet> */
    public array $sheets = [];

    /** @var array<string,array{sheet:string,row:int}> */
    public array $rows = [];

    /** @var array<string,string> reference of a single cell, e.g. "Study.tax" => "'Study'!$B$12" */
    public array $cells = [];

    public function __construct(public readonly ModelData $m, public readonly Labels $labels)
    {
        $this->wb = new Workbook('ZAVERO Manufacturing Pro');
        $this->th = new Theme($this->wb->styles);
        $this->rtl = $labels->lang === 'ar';
    }

    public function t(string $key, string|int|float ...$args): string
    {
        return $this->labels->get($key, ...$args);
    }

    public function s(string $name): int
    {
        return $this->th->s($name);
    }

    public function sheet(string $key, string $title): Sheet
    {
        $sheet = $this->wb->sheet($title, $this->rtl);
        $this->sheets[$key] = $sheet;

        return $sheet;
    }

    /** Letter of the column of month $m. */
    public function col(int $m): string
    {
        return Sheet::col(self::FIRST + $m);
    }

    public function lastCol(): string
    {
        return $this->col($this->m->D - 1);
    }

    public function firstCol(): string
    {
        return $this->col(0);
    }

    public static function q(string $name): string
    {
        return "'".str_replace("'", "''", $name)."'";
    }

    /** "'Sheet'!" */
    public function pre(string $key): string
    {
        return self::q($this->sheets[$key]->name).'!';
    }

    public function reg(string $key, string $sheetKey, int $row): void
    {
        $this->rows[$key] = ['sheet' => $sheetKey, 'row' => $row];
    }

    /** One cell of a monthly row on any sheet: "'Sheet'!H12". */
    public function x(string $key, string $col): string
    {
        $r = $this->rows[$key] ?? throw new \RuntimeException("Unknown row '$key'");

        return $this->pre($r['sheet']).$col.$r['row'];
    }

    /** The whole monthly range of a row: "'Sheet'!$E$12:$CJ$12". */
    public function rng(string $key): string
    {
        $r = $this->rows[$key] ?? throw new \RuntimeException("Unknown row '$key'");

        return $this->pre($r['sheet']).'$'.$this->firstCol().'$'.$r['row'].':$'.$this->lastCol().'$'.$r['row'];
    }

    public function rowOf(string $key): int
    {
        return ($this->rows[$key] ?? throw new \RuntimeException("Unknown row '$key'"))['row'];
    }

    /** Calendar rows: idx, date, year, mnum, yidx, op, study, first, later, yend, oppos */
    public function cal(string $what, string $col): string
    {
        return $this->x('cal.'.$what, $col);
    }

    public function calRng(string $what): string
    {
        return $this->rng('cal.'.$what);
    }

    /**
     * The value of a year row for the month in column $col: INDEX over the year columns by that month's year number.
     * $key is "sheet.row" of a row written with yearInput() / yearCalc().
     */
    public function pickRow(string $key, string $col, bool $unused = false): string
    {
        $r = $this->rows[$key] ?? throw new \RuntimeException("Unknown row '$key'");
        $last = Sheet::col(self::FIRST + $this->m->Y - 1);
        $range = $this->pre($r['sheet']).'$'.Sheet::col(self::FIRST).'$'.$r['row'].':$'.$last.'$'.$r['row'];

        return 'INDEX('.$range.',1,MIN('.$this->cal('yidx', $col).','.($this->m->Y - 1).')+1)';
    }

    /** What a row held $lag months ago: SUMPRODUCT over the month numbers (no volatile OFFSET). $range is the whole monthly range of the row. */
    public function shift(string $range, string $lag, string $col): string
    {
        return 'SUMPRODUCT(('.$this->calRng('idx').'='.$this->cal('idx', $col).'-'.$lag.')*'.$range.')';
    }

    public function name(string $key): string
    {
        return $this->cells[$key] ?? throw new \RuntimeException("Unknown cell '$key'");
    }
}
