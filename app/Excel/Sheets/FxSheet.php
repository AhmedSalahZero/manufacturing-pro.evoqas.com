<?php

namespace App\Excel\Sheets;

use App\Excel\Ctx;
use App\Excel\Grid;
use App\Excel\ModelData;

// The exchange-rate forecast of the study: for each foreign currency, how many units of the study currency one unit buys, year by year.
// Only built when the study exports and has at least one rate. The product sheets pick the rate of each year from here.
final class FxSheet
{
    public static function build(Ctx $x): void
    {
        $m = $x->m;
        if ($m->fx === []) {
            return;
        }
        $sh = $x->sheet('fx', $x->t('n.fx'));
        $g = new Grid($x, $sh, 'fx');
        $g->title($x->t('fx.title'), $x->t('fx.sub'));
        $g->section($x->t('fx.title'));
        $g->yearHeader();
        foreach ($m->fx as $row) {
            $code = (string) ($row['currency'] ?? '');
            $g->yearInput($code, $x->t('fx.row', ModelData::currencyName($code)), $m->yearly((array) ($row['rates'] ?? [])), 'in_num', $x->t('fx.note'));
        }
        $sh->freeze(4, Ctx::FIRST);
    }
}
