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
        $g->plan(array_merge($keys, ['_', 'S', 'H'], array_map(fn ($i) => 'rs'.$i, array_keys($m->products))));
        foreach ($m->products as $i => $pr) {
            $g->line('v'.$i, (string) ($pr['name'] ?? ''), fn (K $k) => $k->x(SalesSheet::key($i).'.v'), ['total' => 'sum']);
        }
        $g->line('vT', $x->t('share.total'), function (K $k) use ($m, $g) {
            return $m->products === [] ? '0' : 'SUM('.$k->c.$g->rowOf('v0').':'.$k->c.$g->rowOf('v'.(count($m->products) - 1)).')';
        }, ['total' => 'sum', 'bold' => true]);
        $g->blank();
        $g->section($x->t('share.shares'));
        $g->monthHeader();
        foreach ($m->products as $i => $pr) {
            $g->line('rs'.$i, (string) ($pr['name'] ?? ''), fn (K $k) => "IF({$k->r('vT')}=0,0,{$k->r('v'.$i)}/{$k->r('vT')})", ['style' => 'pctf']);
        }
        $sh->freeze(4, Ctx::FIRST);
    }

    /**
     * The share of a product in a cost split by typed percentages: its percentage (if it is already selling) over the percentages of
     * all the products already selling that month.
     */
    public static function typed(K $k, string $pctCell, string $sellCell, string $pctRange, string $sellRange): string
    {
        $idx = $k->cal('idx');

        return "IF(SUMPRODUCT($pctRange*($idx>=$sellRange))=0,0,$pctCell*IF($idx>=$sellCell,1,0)/SUMPRODUCT($pctRange*($idx>=$sellRange)))";
    }
}
