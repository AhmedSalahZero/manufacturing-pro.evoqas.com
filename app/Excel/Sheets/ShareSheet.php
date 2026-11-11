<?php

namespace App\Excel\Sheets;

use App\Excel\Ctx;
use App\Excel\Grid;
use App\Excel\K;

// Each product's share of the month's sales value. Costs that are "shared by revenue" (staff, expenses, depreciation) use these rows.
final class ShareSheet
{
    public static function build(Ctx $x): void
    {
        $m = $x->m;
        $sh = $x->sheet('share', $x->t('n.share'));
        $g = new Grid($x, $sh, 'share');
        $g->title($x->t('share.title'), $x->t('share.sub'));
        $g->section($x->t('share.sales'));
        $g->monthHeader();
        $keys = [];
        foreach ($m->products as $i => $_) {
            $keys[] = 'v'.$i;
        }
        $keys[] = 'vT';
        $g->plan(array_merge($keys, ['_', 'S', 'H'], array_map(fn ($i) => 'act'.$i, array_keys($m->products)), ['nA', '_', 'S', 'H'], array_map(fn ($i) => 'rs'.$i, array_keys($m->products))));
        foreach ($m->products as $i => $pr) {
            $g->line('v'.$i, (string) ($pr['name'] ?? ''), fn (K $k) => $k->x(SalesSheet::key($i).'.v'), ['total' => 'sum']);
        }
        $g->line('vT', $x->t('share.total'), function (K $k) use ($m, $g) {
            return $m->products === [] ? '0' : 'SUM('.$k->c.$g->rowOf('v0').':'.$k->c.$g->rowOf('v'.(count($m->products) - 1)).')';
        }, ['total' => 'sum', 'bold' => true]);
        $g->blank();
        $g->section($x->t('share.active'));
        $g->monthHeader();
        // a product is "active" in a month in which it makes or sells something
        foreach ($m->products as $i => $pr) {
            $g->line('act'.$i, (string) ($pr['name'] ?? ''), fn (K $k) => 'IF(OR('.$k->x(SalesSheet::key($i).'.made').">0,{$k->x(SalesSheet::key($i).'.q')}>0,{$k->r('v'.$i)}>0),1,0)", ['style' => 'flag']);
        }
        $g->line('nA', $x->t('share.nAct'), fn (K $k) => $m->products === [] ? '0' : 'SUM('.$k->c.$g->rowOf('act0').':'.$k->c.$g->rowOf('act'.(count($m->products) - 1)).')', ['bold' => true]);
        $g->blank();
        $g->section($x->t('share.shares'));
        $g->monthHeader();
        foreach ($m->products as $i => $pr) {
            $g->line('rs'.$i, (string) ($pr['name'] ?? ''), fn (K $k) => "IF({$k->r('vT')}>0,{$k->r('v'.$i)}/{$k->r('vT')},IF({$k->r('nA')}>0,{$k->r('act'.$i)}/{$k->r('nA')},0))", ['style' => 'pctf']);
        }
        $sh->freeze(4, Ctx::FIRST);
    }

    /** The "active" flags of all the products in one month, as a column range on the share sheet. */
    public static function actRange(Ctx $x, string $col): string
    {
        return $x->pre('share').$col.'$'.$x->rowOf('share.act0').':'.$col.'$'.$x->rowOf('share.act'.(count($x->m->products) - 1));
    }

    /**
     * The share of product $i in a cost split by typed percentages: its percentage (if it makes or sells that month) over the percentages of
     * all the products that make or sell that month. When none does, nothing is shared (the cost goes to general expenses).
     */
    public static function typed(K $k, string $pctCell, string $pctRange, int $i): string
    {
        $act = self::actRange($k->ctx, $k->c);

        return "IF(SUMPRODUCT($pctRange*$act)=0,0,$pctCell*".$k->x('share.act'.$i)."/SUMPRODUCT($pctRange*$act))";
    }

    /** What is left of a cost after the products took their shares: $whole minus the allocated rows (tiny rounding leftovers count as nothing). */
    public static function left(string $whole, string $allocated): string
    {
        return "IF(ABS($whole-($allocated))<0.000001,0,$whole-($allocated))";
    }
}
