<?php

namespace App\Excel;

// The column a monthly formula is being written for: its letters, the previous column's, and shortcuts to rows.
final class K
{
    public readonly string $c;
    public readonly string $p;
    public readonly string $n;

    public function __construct(public readonly Ctx $ctx, public readonly Grid $g, public readonly int $m)
    {
        $this->c = $ctx->col($m);
        $this->p = $m === 0 ? Sheet::col(Ctx::FIRST - 1) : $ctx->col($m - 1);
        $this->n = Sheet::col(Ctx::FIRST + $m + 1);
    }

    /** a row of this sheet, this month: "H12" */
    public function r(string $rowKey): string
    {
        return $this->c.$this->g->rowOf($rowKey);
    }

    /** a row of this sheet, the month before */
    public function pr(string $rowKey): string
    {
        return $this->p.$this->g->rowOf($rowKey);
    }

    /** a row of another sheet (full key "sheet.row"), this month */
    public function x(string $key): string
    {
        return $this->ctx->x($key, $this->c);
    }

    /** a row of another sheet, the month before */
    public function px(string $key): string
    {
        return $this->ctx->x($key, $this->p);
    }

    /** a calendar value of this month: op, study, idx, ... */
    public function cal(string $what): string
    {
        return $this->ctx->cal($what, $this->c);
    }

    public function pcal(string $what): string
    {
        return $this->ctx->cal($what, $this->p);
    }

    public function ncal(string $what): string
    {
        return $this->ctx->cal($what, $this->n);
    }
}
