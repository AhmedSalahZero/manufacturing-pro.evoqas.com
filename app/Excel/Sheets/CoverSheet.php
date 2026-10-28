<?php

namespace App\Excel\Sheets;

use App\Excel\Ctx;
use App\Excel\Sheet;

// The Cover: ZAVERO brand, a place for the client's logo, the study, the checks, the key results, a legend and the contents with links.
final class CoverSheet
{
    /** Sheet keys in the order of the tabs (product sheets are added at their place). */
    public static function order(Ctx $x): array
    {
        $keys = ['cover', 'dash', 'study'];
        foreach ($x->m->products as $i => $_) {
            $keys[] = SalesSheet::key($i);
        }
        array_push($keys, 'rm', 'share', 'mp', 'exp', 'fa', 'ob', 'cost', 'is', 'tax', 'cf', 'bs', 'cal');

        return array_values(array_filter($keys, fn ($k) => isset($x->sheets[$k])));
    }

    public static function build(Ctx $x): void
    {
        $m = $x->m;
        $sh = $x->sheet('cover', $x->t('n.cover'));
        $sh->protect(false)->gridlines(false);
        $sh->width(1, 3)->width(2, 48)->width(3, 20)->width(4, 20)->width(5, 20)->width(6, 20)->width(7, 20)->width(8, 3);
        // brand and the logo place
        $sh->set(2, 2, 'ZAVERO', $x->s('brand'))->height(2, 40);
        $sh->set(3, 2, 'Manufacturing Pro', $x->s('brand_sub'));
        $sh->set(4, 2, $x->t('cv.tagline'), $x->s('subtitle'));
        for ($r = 2; $r <= 5; $r++) {
            for ($c = 5; $c <= 7; $c++) {
                $sh->set($r, $c, $r === 2 && $c === 5 ? $x->t('cv.logo') : '', $x->s('logo'));
            }
        }
        $sh->merge(2, 5, 5, 7);

        // the study
        $r = 7;
        self::band($x, $sh, $r++, $x->t('cv.study'));
        $sh->set($r, 2, $x->t('study.name'), $x->s('label_i'))->set($r, 3, (string) ($m->p['name'] ?? ''), $x->s('label_b'));
        $r++;
        $sh->set($r, 2, $x->t('study.company'), $x->s('label_i'))->set($r, 3, $m->new ? $x->t('study.new') : $x->t('study.existing'), $x->s('label_b'));
        $r++;
        $sh->set($r, 2, $x->t('cv.period'), $x->s('label_i'))->formula($r, 3, 'TEXT(Z_Start,"yyyy-mm")&" → "&TEXT(EDATE(Z_Start,Z_Months-1),"yyyy-mm")', $x->s('label_b'));
        $r++;
        $sh->set($r, 2, $x->t('cv.generated'), $x->s('label_i'))->set($r, 3, gmdate('Y-m-d').'  ·  model build '.\App\Excel\ModelBuilder::BUILD, $x->s('label_b'));
        if ($x->m->sourceNote !== '') {
            $sh->set($r + 1, 2, $x->m->sourceNote, $x->s('note'));
        }
        $r += 3;

        // key results
        self::band($x, $sh, $r++, $x->t('cv.results'));
        $tiles = [[$x->t('dash.kNpv'), 'dash.npv', 'kpi'], [$x->t('dash.kIrr'), 'dash.irr', 'kpi_pct'], [$x->t('dash.kPayback'), 'dash.pYears', 'kpi'], [$x->t('dash.kInvest'), 'dash.iTotal', 'kpi']];
        foreach ($tiles as $i => [$label, $key, $style]) {
            $c = 2 + $i + ($i > 0 ? 0 : 0);
            $col = [2, 3, 4, 5][$i];
            $sh->set($r, $col, $label, $x->s('kpi_lbl'));
            $sh->formula($r + 1, $col, 'IF('.$x->name($key).'="","n/a",'.$x->name($key).')', $x->s($style));
            unset($c);
        }
        $sh->height($r, 24)->height($r + 1, 30);
        $r += 3;

        // checks
        self::band($x, $sh, $r++, $x->t('cv.checks'));
        $okRows = [];
        $chk = function (string $label, string $formula) use ($x, $sh, &$r, &$okRows) {
            $sh->set($r, 2, $label, $x->s('label_i'))->formula($r, 3, $formula, $x->s('ok'));
            $okRows[] = $r;
            $r++;
        };
        $ok = $x->t('cv.ok');
        $bad = $x->t('cv.check');
        $chk($x->t('bs.chk'), 'IF(SUMPRODUCT(ABS('.$x->rng('bs.chk').'))<0.01,"'.$ok.'","'.$bad.'")');
        if (! $m->new) {
            $chk($x->t('ob.check'), 'IF(ABS('.$x->name('ob.chkD').')<0.01,"'.$ok.'","'.$bad.'")');
        }
        if ($m->assets !== []) {
            $n = [];
            foreach ($m->assets as $j => $_) {
                $n[] = $x->name('fa.f'.$j.'_nsteps').'<='.(FixedAssetSheet::PERIODS - 1);
            }
            $chk($x->t('cv.loanLimit', FixedAssetSheet::PERIODS - 1), 'IF(AND('.implode(',', $n).'),"'.$ok.'","'.$bad.'")');
        }
        foreach ($okRows as $or) {
            $sh->conditional('C'.$or, 'C'.$or.'="'.$bad.'"', 0)->conditional('C'.$or, 'C'.$or.'="'.$ok.'"', 1);
        }
        $r++;

        // legend
        self::band($x, $sh, $r++, $x->t('cv.legend'));
        $sh->set($r, 2, $x->t('cv.legIn'), $x->s('label_i'))->set($r, 3, '123', $x->s('legend_in'));
        $r++;
        $sh->set($r, 2, $x->t('cv.legLock'), $x->s('label_i'))->set($r, 3, '123', $x->s('lock_int'));
        $r++;
        $sh->set($r, 2, $x->t('cv.legCalc'), $x->s('label_i'))->set($r, 3, '123', $x->s('num'));
        $r += 2;

        // how to use
        self::band($x, $sh, $r++, $x->t('cv.how'));
        foreach (['cv.h1', 'cv.h2', 'cv.h3', 'cv.h4', 'cv.h5'] as $k) {
            $sh->set($r, 2, '• '.$x->t($k), $x->s('label'));
            $r++;
        }
        $r++;

        // contents
        self::band($x, $sh, $r++, $x->t('cv.contents'));
        foreach (self::order($x) as $key) {
            if ($key === 'cover') {
                continue;
            }
            $s = $x->sheets[$key];
            $desc = preg_match('/^s\d+$/', $key) ? 'sales' : $key;
            $sh->set($r, 2, $s->name, $x->s('link'))->link($r, 2, Ctx::q($s->name).'!A1');
            $sh->set($r, 3, $x->t('cv.d.'.$desc), $x->s('label'));
            $r++;
        }
        $sh->tab('FF0E2350');
    }

    private static function band(Ctx $x, Sheet $sh, int $r, string $text): void
    {
        for ($c = 2; $c <= 7; $c++) {
            $sh->set($r, $c, $c === 2 ? $text : '', $x->s('section'));
        }
        $sh->height($r, 20);
    }
}
