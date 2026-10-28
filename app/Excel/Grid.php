<?php

namespace App\Excel;

// A sheet with a monthly grid: label in A, note in B, total in C, an empty "before the first month" column D, then one column per month.
// Rows are written one by one with a closure that gives the formula of each month.
final class Grid
{
    public int $row = 1;

    /** @var array<string,int> */
    private array $keys = [];

    public function __construct(public readonly Ctx $ctx, public readonly Sheet $sheet, public readonly string $key)
    {
        $sheet->width(1, 46)->width(2, 24)->width(3, 15)->width(4, 3);
        for ($m = 0; $m < $ctx->m->D; $m++) {
            $sheet->width(Ctx::FIRST + $m, 12);
        }
    }

    public function rowOf(string $rowKey): int
    {
        return $this->keys[$rowKey] ?? throw new \RuntimeException("Unknown row '$rowKey' on {$this->key}");
    }

    public function has(string $rowKey): bool
    {
        return isset($this->keys[$rowKey]);
    }

    /** Whole monthly range of one of this sheet's rows: "$E$12:$CJ$12". */
    public function rng(string $rowKey): string
    {
        return '$'.$this->ctx->firstCol().'$'.$this->rowOf($rowKey).':$'.$this->ctx->lastCol().'$'.$this->rowOf($rowKey);
    }

    /** Tells the registry where a row is so other sheets can use it (key "sheet.row"). */
    public function register(string $rowKey, int $row): void
    {
        if (isset($this->keys[$rowKey]) && $this->keys[$rowKey] !== $row) {
            throw new \RuntimeException("Row '$rowKey' on {$this->key} was planned at {$this->keys[$rowKey]} but written at $row");
        }
        $this->keys[$rowKey] = $row;
        $this->ctx->reg($this->key.'.'.$rowKey, $this->key, $row);
    }

    /**
     * Fixes the rows of the lines about to be written, so a formula can point at a row written after it.
     * Items: a row key; 'S' (a section band, 1 row); 'H' (a month header, 2 rows); '_' (a blank row).
     *
     * @param  list<string>  $items
     */
    public function plan(array $items): void
    {
        $r = $this->row;
        foreach ($items as $item) {
            if ($item === 'S' || $item === '_') {
                $r++;
            } elseif ($item === 'H') {
                $r += 2;
            } else {
                $this->register($item, $r);
                $r++;
            }
        }
    }

    public function title(string $text, ?string $sub = null): void
    {
        $last = Ctx::FIRST + min($this->ctx->m->D, 14) - 1;
        for ($c = 1; $c <= $last; $c++) {
            $this->sheet->set(1, $c, $c === 1 ? $text : '', $this->ctx->s('title'));
        }
        $this->sheet->height(1, 30);
        if ($sub !== null) {
            $this->sheet->set(2, 1, $sub, $this->ctx->s('subtitle'));
        }
        $this->row = 4;
    }

    public function section(string $text): void
    {
        $last = Ctx::FIRST + $this->ctx->m->D - 1;
        for ($c = 1; $c <= $last; $c++) {
            $this->sheet->set($this->row, $c, $c === 1 ? $text : '', $this->ctx->s('section'));
        }
        $this->sheet->height($this->row, 20);
        $this->row++;
    }

    public function blank(int $n = 1): void
    {
        $this->row += $n;
    }

    /** Month header (number, month, year) linked to the Calendar sheet; call it at the start of each monthly section. */
    public function monthHeader(): void
    {
        $x = $this->ctx;
        $this->sheet->set($this->row, 1, $x->t('c.month'), $x->s('hdr_l'))->set($this->row, 2, $x->t('c.unit'), $x->s('hdr'))->set($this->row, 3, $x->t('c.total'), $x->s('hdr'))->set($this->row, 4, '', $x->s('hdr'));
        for ($m = 0; $m < $x->m->D; $m++) {
            $this->sheet->formula($this->row, Ctx::FIRST + $m, $x->cal('date', $x->col($m)), $x->s('hdr_date'));
            $this->sheet->formula($this->row + 1, Ctx::FIRST + $m, $x->cal('idx', $x->col($m)), $x->s('hdr_small'));
        }
        $this->sheet->set($this->row + 1, 1, $x->t('c.monthNo'), $x->s('hdr_small'));
        foreach ([2, 3, 4] as $c) {
            $this->sheet->set($this->row + 1, $c, '', $x->s('hdr_small'));
        }
        $this->row += 2;
    }

    /**
     * One monthly row.
     *
     * @param  callable(K):string  $f   the formula of a month (without "=")
     * @param  array{style?:string,total?:string,note?:string,bold?:bool,before?:string,key?:string,label_style?:string}  $o
     *         total: 'sum' | 'last' | 'max'   before: a formula for the "before the first month" column
     */
    public function line(string $rowKey, string $label, callable $f, array $o = []): int
    {
        $x = $this->ctx;
        $r = $this->row;
        $this->register($rowKey, $r);

        $style = $x->s($o['style'] ?? 'num');
        $this->sheet->set($r, 1, $label, $x->s($o['label_style'] ?? (! empty($o['bold']) ? 'label_b' : 'label_i')));
        if (isset($o['note'])) {
            $this->sheet->set($r, 2, $o['note'], $x->s('note'));
        }
        if (isset($o['before'])) {
            $this->sheet->formula($r, Ctx::FIRST - 1, $o['before'], $style);
        }
        for ($m = 0; $m < $x->m->D; $m++) {
            $this->sheet->formula($r, Ctx::FIRST + $m, $f(new K($x, $this, $m)), $style);
        }
        $first = $x->firstCol().$r;
        $last = $x->lastCol().$r;
        $total = $o['total'] ?? null;
        if ($total === 'sum') {
            $this->sheet->formula($r, 3, "SUM($first:$last)", $x->s('sumcol'));
        } elseif ($total === 'max') {
            $this->sheet->formula($r, 3, "MAX($first:$last)", $x->s('sumcol'));
        }
        $this->row++;

        return $r;
    }

    // ── inputs above the monthly area ───────────────────────────────

    /** Reference to a single input cell of this sheet, usable from any sheet: 'Sheet'!$E$12 */
    public function cell(string $cellKey): string
    {
        return $this->ctx->name($this->key.'.'.$cellKey);
    }

    /** Same, without the sheet name (for formulas on this sheet). */
    public function lcell(string $cellKey): string
    {
        $ref = $this->ctx->name($this->key.'.'.$cellKey);

        return substr($ref, strpos($ref, '!') + 1);
    }

    /** A single value in column E. $value null = empty input. */
    public function scalar(string $cellKey, string $label, mixed $value, string $style = 'in_num', ?string $note = null, bool $formula = false): int
    {
        $x = $this->ctx;
        $r = $this->row;
        $this->sheet->set($r, 1, $label, $x->s('label_i'));
        if ($formula) {
            $this->sheet->formula($r, Ctx::FIRST, (string) $value, $x->s($style));
        } else {
            $this->sheet->set($r, Ctx::FIRST, $value, $x->s($style));
        }
        if ($note !== null) {
            $this->sheet->set($r, 2, $note, $x->s('note'));
        }
        $x->cells[$this->key.'.'.$cellKey] = $x->pre($this->key).'$'.Sheet::col(Ctx::FIRST).'$'.$r;
        $this->row++;

        return $r;
    }

    /** @var list<array{row:int,f:callable,style:string}> */
    private array $pending = [];

    /**
     * A calculated single cell whose formula points at monthly rows written further down: its place is fixed now, the formula is
     * produced by flush() once the rows have been planned.
     *
     * @param  callable():string  $formula
     */
    public function later(string $cellKey, string $label, callable $formula, string $style = 'lock_int', ?string $note = null): int
    {
        $x = $this->ctx;
        $r = $this->row;
        $this->sheet->set($r, 1, $label, $x->s('label_i'));
        if ($note !== null) {
            $this->sheet->set($r, 2, $note, $x->s('note'));
        }
        $x->cells[$this->key.'.'.$cellKey] = $x->pre($this->key).'$'.Sheet::col(Ctx::FIRST).'$'.$r;
        $this->pending[] = ['row' => $r, 'f' => $formula, 'style' => $style];
        $this->row++;

        return $r;
    }

    /** Writes the formulas of the later() cells. Call after plan(). */
    public function flush(): void
    {
        foreach ($this->pending as $p) {
            $this->sheet->formula($p['row'], Ctx::FIRST, ($p['f'])(), $this->ctx->s($p['style']));
        }
        $this->pending = [];
    }

    /** Header of the year columns: the calendar year of each. */
    public function yearHeader(string $rowKey = 'yh', ?string $label = null): void
    {
        $x = $this->ctx;
        $r = $this->row;
        $this->register($rowKey, $r);
        $this->sheet->set($r, 1, $label ?? $x->t('c.year'), $x->s('hdr_l'))->set($r, 2, '', $x->s('hdr'))->set($r, 3, '', $x->s('hdr'))->set($r, 4, '', $x->s('hdr'));
        for ($y = 0; $y < $x->m->Y; $y++) {
            $this->sheet->formula($r, Ctx::FIRST + $y, 'Z_BaseYear+'.$y, $x->s('hdr_int'));
        }
        $this->row++;
    }

    /**
     * A row of year inputs. The last column (the extended year) repeats the year before, as the application does.
     *
     * @param  list<float>  $values  one per year column
     */
    public function yearInput(string $rowKey, string $label, array $values, string $style = 'in_num', ?string $note = null): int
    {
        $x = $this->ctx;
        $r = $this->row;
        $this->register($rowKey, $r);
        $this->sheet->set($r, 1, $label, $x->s('label_i'));
        if ($note !== null) {
            $this->sheet->set($r, 2, $note, $x->s('note'));
        }
        $calc = ['in_num' => 'num2', 'in_int' => 'num', 'in_pct' => 'pct'][$style] ?? 'num';
        for ($y = 0; $y < $x->m->Y; $y++) {
            if ($y === $x->m->Y - 1 && $y > 0) {
                $this->sheet->formula($r, Ctx::FIRST + $y, Sheet::col(Ctx::FIRST + $y - 1).$r, $x->s($calc));
            } else {
                $this->sheet->set($r, Ctx::FIRST + $y, (float) ($values[$y] ?? 0), $x->s($style));
            }
        }
        $this->row++;

        return $r;
    }

    /** A calculated row over the year columns. @param callable(int,string,string):string $f (year index, column letter, previous letter) */
    public function yearCalc(string $rowKey, string $label, callable $f, string $style = 'num', ?string $note = null): int
    {
        $x = $this->ctx;
        $r = $this->row;
        $this->register($rowKey, $r);
        $this->sheet->set($r, 1, $label, $x->s('label_i'));
        if ($note !== null) {
            $this->sheet->set($r, 2, $note, $x->s('note'));
        }
        for ($y = 0; $y < $x->m->Y; $y++) {
            $this->sheet->formula($r, Ctx::FIRST + $y, $f($y, Sheet::col(Ctx::FIRST + $y), Sheet::col(Ctx::FIRST + $y - 1)), $x->s($style));
        }
        $this->row++;

        return $r;
    }

    /** The value of a year row for the month being written: INDEX over the year columns by the month's year number. */
    public function pick(string $rowKey, K $k): string
    {
        return $this->ctx->pickRow($this->key.'.'.$rowKey, $k->c, false);
    }

    /**
     * A collection / payment policy line: cash %, rate 1, days 1, rate 2, days 2, and the worked-out months later and rates.
     *
     * @param  array<string,mixed>  $policy  cash_payment, rate[2], due_in_days[2]
     */
    public function policyRow(string $cellKey, string $label, array $policy): int
    {
        $x = $this->ctx;
        $r = $this->row;
        $sh = $this->sheet;
        $sh->set($r, 1, $label, $x->s('label_i'));
        $days = (array) ($policy['due_in_days'] ?? []);
        $rate = (array) ($policy['rate'] ?? []);
        $val = fn ($v) => ($v === null || $v === '') ? null : (float) $v;
        $sh->set($r, 5, $val($policy['cash_payment'] ?? 0) ?? 0, $x->s('in_pct'));
        $sh->set($r, 6, $val($rate[0] ?? null), $x->s('in_pct'))->set($r, 7, $val($days[0] ?? null), $x->s('in_int'));
        $sh->set($r, 8, $val($rate[1] ?? null), $x->s('in_pct'))->set($r, 9, $val($days[1] ?? null), $x->s('in_int'));
        $sh->formula($r, 10, "IF(G$r=\"\",0,IF(G$r<30,0,ROUND(G$r/30,0)))", $x->s('flag'));
        $sh->formula($r, 11, "IF(I$r=\"\",0,IF(I$r<30,0,ROUND(I$r/30,0)))", $x->s('flag'));
        $sh->formula($r, 12, "IF(G$r=\"\",0,N(F$r))", $x->s('pct'));
        $sh->formula($r, 13, "IF(I$r=\"\",0,N(H$r))", $x->s('pct'));
        $pre = $x->pre($this->key);
        foreach (['cash' => 'E', 'lag1' => 'J', 'lag2' => 'K', 'r1' => 'L', 'r2' => 'M'] as $name => $letter) {
            $x->cells[$this->key.'.'.$cellKey.'.'.$name] = $pre.'$'.$letter.'$'.$r;
        }
        $this->row++;

        return $r;
    }

    public function policyHeader(): void
    {
        $x = $this->ctx;
        $r = $this->row;
        $titles = [1 => $x->t('pol.line'), 2 => '', 3 => '', 4 => '', 5 => $x->t('pol.cash'), 6 => $x->t('pol.rate1'), 7 => $x->t('pol.days1'), 8 => $x->t('pol.rate2'), 9 => $x->t('pol.days2'), 10 => $x->t('pol.lag1'), 11 => $x->t('pol.lag2'), 12 => $x->t('pol.eff1'), 13 => $x->t('pol.eff2')];
        foreach ($titles as $c => $t) {
            $this->sheet->set($r, $c, $t, $x->s($c === 1 ? 'hdr_l' : 'hdr'));
        }
        $this->sheet->height($r, 32);
        $this->row++;
    }

    /** A row of typed month inputs (the cells the person may change). @param array<int,float|int> $values month index => value */
    public function inputLine(string $rowKey, string $label, array $values, string $style = 'in_num', ?string $note = null, string $total = 'sum'): int
    {
        $x = $this->ctx;
        $r = $this->row;
        $this->register($rowKey, $r);
        $this->sheet->set($r, 1, $label, $x->s('label_i'));
        if ($note !== null) {
            $this->sheet->set($r, 2, $note, $x->s('note'));
        }
        for ($m = 0; $m < $x->m->D; $m++) {
            $v = $values[$m] ?? null;
            $this->sheet->set($r, Ctx::FIRST + $m, $v === null || $v == 0 ? null : (float) $v, $x->s($style));
        }
        if ($total === 'sum') {
            $this->sheet->formula($r, 3, 'SUM('.$x->firstCol().$r.':'.$x->lastCol().$r.')', $x->s('sumcol'));
        }
        $this->row++;

        return $r;
    }
}
