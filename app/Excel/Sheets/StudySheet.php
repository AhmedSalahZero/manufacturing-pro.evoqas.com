<?php

namespace App\Excel\Sheets;

use App\Excel\Ctx;
use App\Excel\Grid;
use App\Excel\ModelData;
use App\Excel\Sheet;

// The Study sheet (settings and assumptions) and the Calendar sheet (one column per month: dates and on/off switches).
final class StudySheet
{
    public static function study(Ctx $x): void
    {
        $m = $x->m;
        $sh = $x->sheet('study', $x->t('n.study'));
        $sh->width(1, 46)->width(2, 18)->width(3, 70);
        for ($c = 1; $c <= 3; $c++) {
            $sh->set(1, $c, $c === 1 ? $x->t('study.title') : '', $x->s('title'));
        }
        $sh->height(1, 30)->set(2, 1, $x->t('study.sub'), $x->s('subtitle'));

        $r = 4;
        $band = function (string $text) use ($sh, $x, &$r) {
            for ($c = 1; $c <= 3; $c++) {
                $sh->set($r, $c, $c === 1 ? $text : '', $x->s('section'));
            }
            $r++;
        };
        $name = function (string $n, int $row) use ($x) {
            $x->wb->defineName($n, "'".str_replace("'", "''", $x->sheets['study']->name)."'!\$B\$".$row);
        };

        $band($x->t('study.title'));
        $sh->set($r, 1, $x->t('study.name'), $x->s('label'))->set($r, 2, (string) ($m->p['name'] ?? ''), $x->s('lock_txt'))->set($r, 3, $x->t('c.locked'), $x->s('note')); $r++;
        $sh->set($r, 1, $x->t('study.company'), $x->s('label'))->set($r, 2, $m->new ? $x->t('study.new') : $x->t('study.existing'), $x->s('lock_txt'))->set($r, 3, $x->t('c.locked'), $x->s('note'));
        $r++;
        $sh->set($r, 1, $x->t('study.start'), $x->s('label'))->set($r, 2, (float) $m->serial(0), $x->s('lock_date'))->set($r, 3, $x->t('c.locked'), $x->s('note')); $name('Z_Start', $r); $startRow = $r; $r++;
        $sh->set($r, 1, $x->t('study.years'), $x->s('label'))->set($r, 2, (int) $m->p['duration'], $x->s('lock_int'))->set($r, 3, $x->t('c.locked'), $x->s('note')); $name('Z_Years', $r); $yearsRow = $r; $r++;
        // An existing company is already operating, so its operation starts with the study (the application forces the same);
        // a new company may need time before it starts, so only there is the date an input.
        if ($m->new) {
            $sh->set($r, 1, $x->t('study.opStart'), $x->s('label'))->set($r, 2, (float) $m->serial($m->opIdx), $x->s('in_date'))->set($r, 3, $x->t('study.opLater'), $x->s('note'));
        } else {
            $sh->set($r, 1, $x->t('study.opStart'), $x->s('label'))->formula($r, 2, 'Z_Start', $x->s('lock_date'))->set($r, 3, $x->t('study.opSame'), $x->s('note'));
        }
        $name('Z_OpDate', $r); $opRow = $r; $r++;
        $r++;
        $band($x->t('study.derived'));
        $derived = [
            ['Z_NewCo', 'study.newFlag', $m->new ? '1' : '0'],
            ['Z_Months', 'study.months', "Z_Years*12"],
            ['Z_OpIdx', 'study.opIdx', "(YEAR(Z_OpDate)-YEAR(Z_Start))*12+MONTH(Z_OpDate)-MONTH(Z_Start)"],
            ['Z_BaseYear', 'study.baseYear', "YEAR(Z_Start)"],
            ['Z_OpYear', 'study.opYear', "YEAR(Z_OpDate)-YEAR(Z_Start)"],
            ['Z_StudyEnd', 'study.studyEnd', "(YEAR(EDATE(Z_Start,12*Z_Years-1))-YEAR(Z_Start))*12+12-MONTH(Z_Start)"],
            ['Z_ExtEnd', 'study.extEnd', "Z_StudyEnd+12"],
        ];
        foreach ($derived as [$n, $label, $f]) {
            $sh->set($r, 1, $x->t($label), $x->s('label'))->formula($r, 2, $f, $x->s('lock_int'));
            $name($n, $r);
            $r++;
        }
        $r++;
        $band($x->t('study.assump'));
        $inputs = [
            ['Z_Tax', 'study.tax', 'tax_rate'], ['Z_Growth', 'study.growth', 'perpetual_growth_rate'], ['Z_Return', 'study.return', 'return_rate'],
            ['Z_SalInc', 'study.salInc', 'salaries_annual_increase'], ['Z_SalTax', 'study.salTax', 'salaries_tax_rate'], ['Z_SocIns', 'study.socIns', 'social_insurance_rate'],
        ];
        foreach ($inputs as [$n, $label, $field]) {
            $sh->set($r, 1, $x->t($label), $x->s('label'))->set($r, 2, (float) ($m->p[$field] ?? 0), $x->s('in_pct'));
            $name($n, $r);
            $r++;
        }
        $r++;
        $sh->set($r, 1, $x->t('study.fixedNote'), $x->s('note'));
        unset($startRow, $yearsRow, $opRow);
    }

    public static function calendar(Ctx $x): void
    {
        $m = $x->m;
        $sh = $x->sheet('cal', $x->t('n.cal'));
        $g = new Grid($x, $sh, 'cal');
        $g->title($x->t('cal.title'), $x->t('cal.sub'));
        $keys = ['idx', 'date', 'year', 'mnum', 'yidx', 'op', 'study', 'first', 'later', 'yend', 'oppos'];
        $g->plan($keys);
        $first = $x->firstCol();
        $last = $x->lastCol();

        // idx: typed numbers 0..D-1
        $r = $g->row;
        $g->register('idx', $r);
        $sh->set($r, 1, $x->t('cal.idx'), $x->s('label_b'));
        for ($i = 0; $i < $m->D; $i++) {
            $sh->set($r, Ctx::FIRST + $i, $i, $x->s('hdr_int'));
        }
        $g->row++;

        $g->line('date', $x->t('cal.date'), fn ($k) => "DATE(YEAR(Z_Start),MONTH(Z_Start)+{$k->r('idx')},1)", ['style' => 'date']);
        $g->line('year', $x->t('cal.year'), fn ($k) => "YEAR({$k->r('date')})", ['style' => 'flag']);
        $g->line('mnum', $x->t('cal.mnum'), fn ($k) => "MONTH({$k->r('date')})", ['style' => 'flag']);
        $g->line('yidx', $x->t('cal.yidx'), fn ($k) => "{$k->r('year')}-Z_BaseYear", ['style' => 'flag']);
        $g->line('op', $x->t('cal.op'), fn ($k) => "IF(AND({$k->r('idx')}>=Z_OpIdx,{$k->r('idx')}<=Z_ExtEnd),1,0)", ['style' => 'flag']);
        $g->line('study', $x->t('cal.study'), fn ($k) => "IF({$k->r('idx')}<Z_Months,1,0)", ['style' => 'flag']);
        $g->line('first', $x->t('cal.first'), fn ($k) => "{$k->r('op')}*IF({$k->r('yidx')}=Z_OpYear,1,0)", ['style' => 'flag']);
        $g->line('later', $x->t('cal.later'), fn ($k) => "{$k->r('op')}-{$k->r('first')}", ['style' => 'flag']);
        $g->line('yend', $x->t('cal.yend'), fn ($k) => "{$k->r('study')}*IF(OR({$k->r('idx')}=Z_Months-1,{$k->n}{$g->rowOf('year')}<>{$k->r('year')}),1,0)", ['style' => 'flag']);
        $g->line('oppos', $x->t('cal.oppos'), fn ($k) => "IF({$k->r('op')}=1,SUM(\$D\${$g->rowOf('op')}:{$k->p}{$g->rowOf('op')}),0)", ['style' => 'flag']);
        $sh->freeze(4, Ctx::FIRST);
        unset($first, $last);
    }
}
