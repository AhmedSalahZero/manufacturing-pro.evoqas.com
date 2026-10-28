<?php

namespace App\Excel\Sheets;

use App\Excel\Ctx;
use App\Excel\Grid;
use App\Excel\K;
use App\Excel\ModelData;
use App\Excel\Sheet;

// The Expenses page: every expense line (fixed monthly amount, one-time, % of revenue, cost per unit) with its category and payment terms,
// the monthly expense, what is paid when, what is still owed, and the part that belongs to each product (manufacturing categories).
final class ExpenseSheet
{
    public const CATEGORIES = ['manufacturing-expenses', 'other-operation-expenses', 'marketing', 'sales', 'general-expenses', 'pre-operating-expenses', 'start-up-expenses'];
    public const MANUFACTURING = ['manufacturing-expenses', 'other-operation-expenses'];
    public const TERMS = ['cash', 'quarterly', 'semi-annually', 'annually', 'customize'];
    public const INTERVALS = ['quarterly', 'semi-annually', 'annually'];

    /** @return array<string,string> */
    public static function categoryNames(Ctx $x): array
    {
        $out = [];
        foreach (self::CATEGORIES as $c) {
            $out[$c] = $x->t('exp.cat.'.$c);
        }

        return $out;
    }

    public static function build(Ctx $x): void
    {
        $m = $x->m;
        $list = $m->expenses;
        if ($list === []) {
            return;
        }
        $P = count($m->products);
        $sh = $x->sheet('exp', $x->t('n.exp'));
        $g = new Grid($x, $sh, 'exp');
        $g->title($x->t('exp.title'), $x->t('exp.sub'));
        $cats = self::categoryNames($x);
        if (! $m->new) {
            unset($cats['pre-operating-expenses'], $cats['start-up-expenses']);
        }
        $terms = [];
        foreach (self::TERMS as $t) {
            $terms[$t] = $x->t('exp.terms.'.$t);
        }
        $ints = [];
        foreach (self::INTERVALS as $t) {
            $ints[$t] = $x->t('exp.int.'.$t);
        }
        $yes = $x->t('c.yes');
        $no = $x->t('c.no');
        $pre = $x->pre('exp');
        $idxR = $x->calRng('idx');

        foreach ($list as $e => $row) {
            $p = 'e'.$e.'_';
            $kind = (string) $row['relation_name'];
            $name = (string) ($row['name'] ?? '');
            $g->section($x->t('exp.block', $e + 1, $name));
            $sc = fn (string $key, string $label, mixed $value, string $style, ?string $note = null, bool $f = false) => $g->scalar($p.$key, $label, $value, $style, $note, $f);
            $sc('name', $x->t('exp.name'), $name, 'in_txt');
            $sc('kind', $x->t('exp.kind'), $x->t('exp.kinds.'.$kind), 'lock_txt', $x->t('c.locked'));
            $r = $g->row;
            $cat = $row['category_id'];
            $sh->set($r, 1, $x->t('exp.category'), $x->s('label_i'))->set($r, Ctx::FIRST, $cats[$cat] ?? reset($cats), $x->s('in_list'));
            $sh->list(Sheet::col(Ctx::FIRST).$r, array_values($cats));
            $x->cells[$g->key.'.'.$p.'cat'] = $pre.'$'.Sheet::col(Ctx::FIRST).'$'.$r;
            $g->row++;
            $sc('start', $x->t('exp.start'), (float) $m->serial((int) $row['start_date']), 'in_date');
            $sc('sIdx', $x->t('exp.sIdx'), '(YEAR('.$g->lcell($p.'start').')-YEAR(Z_Start))*12+MONTH('.$g->lcell($p.'start').')-MONTH(Z_Start)', 'lock_int', null, true);
            if ($kind !== 'one_time_expense') {
                $sc('end', $x->t('exp.end'), (float) $m->serial(min((int) $row['end_date'], $m->D - 1)), 'in_date', $x->t('exp.endNote'));
                $sc('eIdx', $x->t('exp.eIdx'), 'IF((YEAR('.$g->lcell($p.'end').')-YEAR(Z_Start))*12+MONTH('.$g->lcell($p.'end').')-MONTH(Z_Start)>=Z_StudyEnd,Z_ExtEnd,(YEAR('.$g->lcell($p.'end').')-YEAR(Z_Start))*12+MONTH('.$g->lcell($p.'end').')-MONTH(Z_Start))', 'lock_int', null, true);
            }
            if ($kind === 'fixed_monthly_repeating_amount') {
                $sc('amount', $x->t('exp.amount'), (float) ($row['amount'] ?? 0), 'in_num', $x->t('exp.perMonth'));
                $r = $g->row;
                $iv = (string) ($row['increase_interval'] ?: 'annually');
                $sh->set($r, 1, $x->t('exp.incEvery'), $x->s('label_i'))->set($r, Ctx::FIRST, $ints[$iv] ?? $ints['annually'], $x->s('in_list'));
                $sh->list(Sheet::col(Ctx::FIRST).$r, array_values($ints));
                $x->cells[$g->key.'.'.$p.'int'] = $pre.'$'.Sheet::col(Ctx::FIRST).'$'.$r;
                $g->row++;
                $sc('rate', $x->t('exp.incRate'), (float) ($row['increase_rate'] ?? 0), 'in_pct');
                $iref = $g->lcell($p.'int');
                $sc('every', $x->t('exp.every'), "IF($iref=\"{$ints['quarterly']}\",3,IF($iref=\"{$ints['semi-annually']}\",6,12))", 'lock_int', null, true);
            } elseif ($kind === 'one_time_expense') {
                $sc('amount', $x->t('exp.amountOnce'), (float) ($row['amount'] ?? 0), 'in_num');
                $sc('months', $x->t('exp.amort'), (float) ($row['amortization_months'] ?: 12), 'in_int', $x->t('exp.months'));
                $sc('mEff', $x->t('exp.mEff'), 'MAX(1,INT('.$g->lcell($p.'months').'))', 'lock_int', null, true);
            } elseif ($kind === 'expense_as_percentage') {
                $sc('pct', $x->t('exp.pct'), (float) ($row['monthly_percentage'] ?? 0), 'in_pct', $x->t('exp.pctNote'));
            } else {
                $sc('unit', $x->t('exp.unit'), (float) ($row['monthly_cost_of_unit'] ?? 0), 'in_num', $x->t('exp.unitNote'));
            }

            // payment terms
            $r = $g->row;
            $t = (string) ($row['payment_terms'] ?: 'cash');
            $sh->set($r, 1, $x->t('exp.payTerms'), $x->s('label_i'))->set($r, Ctx::FIRST, $terms[$t] ?? $terms['cash'], $x->s('in_list'));
            $sh->list(Sheet::col(Ctx::FIRST).$r, array_values($terms));
            $x->cells[$g->key.'.'.$p.'terms'] = $pre.'$'.Sheet::col(Ctx::FIRST).'$'.$r;
            $g->row++;
            $tref = $g->lcell($p.'terms');
            $sc('B', $x->t('exp.block3'), "IF($tref=\"{$terms['quarterly']}\",3,IF($tref=\"{$terms['semi-annually']}\",6,IF($tref=\"{$terms['annually']}\",12,1)))", 'lock_int', null, true);
            $anchor = in_array($kind, ['expense_as_percentage', 'cost_per_unit'], true) ? 'MAX('.$g->lcell($p.'sIdx').',Z_OpIdx)' : $g->lcell($p.'sIdx');
            $sc('anchor', $x->t('exp.anchor'), $anchor, 'lock_int', null, true);
            $policy = (array) ($row['custom_collection_policy'] ?? []);
            $lines = max(4, count($policy));
            $r = $g->row;
            $sh->set($r, 1, $x->t('exp.custom'), $x->s('label_b'))->set($r, 5, $x->t('exp.days'), $x->s('hdr'))->set($r, 6, $x->t('exp.rate'), $x->s('hdr'))->set($r, 7, $x->t('pol.lag1'), $x->s('hdr'))->set($r, 8, $x->t('pol.eff1'), $x->s('hdr'));
            $g->row++;
            $pairs = [];
            foreach ($policy as $days => $rate) {
                $pairs[] = [(int) $days, (float) $rate];
            }
            $firstLine = $g->row;
            for ($l = 0; $l < $lines; $l++) {
                $r = $g->row;
                $sh->set($r, 1, $x->t('exp.line', $l + 1), $x->s('label_i'));
                $sh->set($r, 5, isset($pairs[$l]) ? (float) $pairs[$l][0] : null, $x->s('in_int'))->set($r, 6, isset($pairs[$l]) ? (float) $pairs[$l][1] : null, $x->s('in_pct'));
                $sh->formula($r, 7, "IF(E$r=\"\",0,IF(E$r<30,0,ROUND(E$r/30,0)))", $x->s('flag'))->formula($r, 8, "IF(E$r=\"\",0,N(F$r))", $x->s('pct'));
                $g->row++;
            }
            $lineRows = range($firstLine, $firstLine + $lines - 1);

            // sharing between products
            $manufacturing = in_array($cat, self::MANUFACTURING, true);
            $byRev = (bool) ($row['is_as_revenue_percentages'] ?? true);
            $pctFirst = $g->row + 1;
            $needTable = $P > 0;
            if ($needTable) {
                $r = $g->row;
                $isPer = in_array($kind, ['expense_as_percentage', 'cost_per_unit'], true);
                $sh->set($r, 1, $x->t('exp.sharing'), $x->s('label_b'));
                if ($isPer) {
                    $sh->set($r, 5, $x->t('exp.included'), $x->s('hdr'));
                } else {
                    $sh->set($r, 5, $x->t('exp.sharePct'), $x->s('hdr'))->set($r, 7, $x->t('mp.sellIdx'), $x->s('hdr'));
                }
                $g->row++;
                if (! $isPer) {
                    $r = $g->row;
                    $sh->set($r, 1, $x->t('mp.byRevenue'), $x->s('label_i'))->set($r, 5, $byRev ? $yes : $no, $x->s('in_list'));
                    $sh->list('E'.$r, [$yes, $no]);
                    $x->cells[$g->key.'.'.$p.'rev'] = $pre.'$E$'.$r;
                    $g->row++;
                }
                $pctFirst = $g->row;
                $selected = array_map('intval', (array) ($row['products'] ?? []));
                $alloc = (array) ($row['product_allocations'] ?? []);
                foreach ($m->products as $i => $pr) {
                    $r = $g->row;
                    $sh->set($r, 1, (string) ($pr['name'] ?? ''), $x->s('label_i'));
                    if ($isPer) {
                        $sh->set($r, 5, in_array((int) $pr['id'], $selected, true) ? $yes : $no, $x->s('in_list'));
                        $sh->list('E'.$r, [$yes, $no]);
                    } else {
                        $sh->set($r, 5, (float) ($alloc[$pr['id']] ?? 0), $x->s('in_pct'));
                        $sh->formula($r, 7, $x->name(SalesSheet::key($i).'.sellIdx'), $x->s('flag'));
                    }
                    $g->row++;
                }
            }
            $pctLast = $g->row - 1;
            $g->blank();

            // monthly
            $g->section($x->t('exp.monthly', $name));
            $g->monthHeader();
            $keys = ['per', 'aft', 'pay', 'beg', 'due', 'end'];
            if ($kind === 'one_time_expense') {
                $keys[] = 'pre';
            }
            foreach ($m->products as $i => $_) {
                $keys[] = 'a'.$i;
            }
            $g->plan(array_map(fn ($k) => $p.$k, $keys));
            $idx = fn (K $k) => $k->cal('idx');
            $sIdx = $g->lcell($p.'sIdx');
            // the sum over the chosen products of one monthly series
            $chosen = function (K $k, string $series) use ($m, $pctFirst, $yes) {
                $parts = [];
                foreach ($m->products as $i => $_) {
                    $parts[] = '(E'.($pctFirst + $i).'="'.$yes.'")*'.$k->x(SalesSheet::key($i).'.'.$series);
                }

                return $parts === [] ? '0' : implode('+', $parts);
            };
            $inRange = fn (K $k) => "{$k->cal('op')}*IF(AND({$k->cal('idx')}>=$sIdx,{$k->cal('idx')}<={$g->lcell($p.'eIdx')}),1,0)";
            switch ($kind) {
                case 'fixed_monthly_repeating_amount':
                    $g->line($p.'per', $x->t('exp.per'), fn (K $k) => "IF(AND({$k->cal('idx')}>=$sIdx,{$k->cal('idx')}<={$g->lcell($p.'eIdx')}),{$g->lcell($p.'amount')}*(1+{$g->lcell($p.'rate')}/100)^INT(({$k->cal('idx')}-$sIdx)/{$g->lcell($p.'every')}),0)", ['total' => 'sum', 'bold' => true]);
                    $g->line($p.'aft', $x->t('exp.aft'), fn (K $k) => $k->r($p.'per'), ['total' => 'sum']);
                    break;
                case 'one_time_expense':
                    $g->line($p.'per', $x->t('exp.perOnce'), fn (K $k) => "IF(AND({$k->cal('idx')}>=$sIdx,{$k->cal('idx')}<$sIdx+{$g->lcell($p.'mEff')}),{$g->lcell($p.'amount')}/{$g->lcell($p.'mEff')},0)", ['total' => 'sum', 'bold' => true]);
                    $g->line($p.'aft', $x->t('exp.aftOnce'), fn (K $k) => "IF({$k->cal('idx')}=$sIdx,{$g->lcell($p.'amount')},0)", ['total' => 'sum']);
                    break;
                case 'expense_as_percentage':
                    $g->line($p.'per', $x->t('exp.perPct'), fn (K $k) => "{$inRange($k)}*{$g->lcell($p.'pct')}/100*({$chosen($k, 'v')})", ['total' => 'sum', 'bold' => true]);
                    $g->line($p.'aft', $x->t('exp.aft'), fn (K $k) => $k->r($p.'per'), ['total' => 'sum']);
                    break;
                default:
                    $g->line($p.'per', $x->t('exp.perUnit'), fn (K $k) => "{$inRange($k)}*{$g->lcell($p.'unit')}*({$chosen($k, 'q')})", ['total' => 'sum', 'bold' => true]);
                    $g->line($p.'aft', $x->t('exp.aft'), fn (K $k) => $k->r($p.'per'), ['total' => 'sum']);
            }
            $aftR = $g->rng($p.'aft');
            $B = $g->lcell($p.'B');
            $anc = $g->lcell($p.'anchor');
            $custom = $terms['customize'];
            $g->line($p.'pay', $x->t('exp.pay'), function (K $k) use ($x, $g, $p, $aftR, $B, $anc, $tref, $custom, $lineRows, $idxR) {
                $sum = [];
                foreach ($lineRows as $lr) {
                    $sum[] = '$H$'.$lr.'/100*'.$x->shift($aftR, '$G$'.$lr, $k->c);
                }
                $idx = $k->cal('idx');

                return 'IF('.$tref.'="'.$custom.'",'.implode('+', $sum).",IF(AND($idx>=$anc,MOD($idx-$anc,$B)=0),SUMPRODUCT(($idxR>=$idx)*($idxR<=$idx+$B-1)*$aftR),0))";
            }, ['total' => 'sum', 'bold' => true, 'note' => $x->t('exp.payNote')]);
            $g->line($p.'beg', $x->t('exp.beg'), fn (K $k) => $k->pr($p.'end'));
            $g->line($p.'due', $x->t('exp.due'), fn (K $k) => "{$k->r($p.'beg')}+{$k->r($p.'aft')}");
            $g->line($p.'end', $x->t('exp.endBal'), fn (K $k) => "{$k->r($p.'due')}-{$k->r($p.'pay')}", ['bold' => true]);
            if ($kind === 'one_time_expense') {
                $g->line($p.'pre', $x->t('exp.prepaid'), fn (K $k) => "IF(AND({$k->cal('idx')}>=$sIdx,{$k->cal('idx')}<$sIdx+{$g->lcell($p.'mEff')}),{$g->lcell($p.'amount')}-{$g->lcell($p.'amount')}/{$g->lcell($p.'mEff')}*({$k->cal('idx')}-$sIdx+1),0)", ['note' => $x->t('exp.prepaidNote')]);
            }
            $manuf = '(OR('.implode(',', array_map(fn ($c) => $g->lcell($p.'cat').'="'.$cats[$c].'"', array_values(array_filter(self::MANUFACTURING, fn ($c) => isset($cats[$c]))))).')*(LEN('.$g->lcell($p.'name').')>0))';
            foreach ($m->products as $i => $pr) {
                $g->line($p.'a'.$i, $x->t('exp.toProduct', (string) ($pr['name'] ?? '')), function (K $k) use ($x, $g, $p, $i, $kind, $manuf, $pctFirst, $pctLast, $yes, $inRange) {
                    if (in_array($kind, ['expense_as_percentage', 'cost_per_unit'], true)) {
                        $unit = $kind === 'expense_as_percentage' ? $g->lcell($p.'pct').'/100*'.$k->x(SalesSheet::key($i).'.v') : $g->lcell($p.'unit').'*'.$k->x(SalesSheet::key($i).'.q');

                        return "$manuf*(E".($pctFirst + $i).'="'.$yes.'")*'.$inRange($k)."*($unit)";
                    }
                    $range = fn (string $col) => '$'.$col.'$'.$pctFirst.':$'.$col.'$'.$pctLast;
                    $row = $pctFirst + $i;
                    $typed = ShareSheet::typed($k, '$E$'.$row, '$G$'.$row, $range('E'), $range('G'));
                    $base = $kind === 'one_time_expense' ? $k->r($p.'per') : $k->r($p.'aft');

                    return "$manuf*IF(".$g->lcell($p.'rev').'="'.$yes.'",'.$x->x('share.rs'.$i, $k->c).",$typed)*$base";
                }, ['total' => 'sum']);
            }
            $g->blank(2);
        }

        // totals
        $g->section($x->t('exp.totals'));
        $g->monthHeader();
        $planned = [];
        foreach (self::CATEGORIES as $c) {
            if (isset($cats[$c])) {
                $planned[] = 'cat_'.$c;
            }
        }
        array_push($planned, 'payT', 'endT', 'preT', '_', 'S', 'H');
        foreach ($m->products as $i => $_) {
            $planned[] = 'toP'.$i;
        }
        $g->plan($planned);
        $catSum = function (K $k, string $c) use ($list, $g, $cats) {
            $parts = [];
            foreach ($list as $e => $_) {
                $parts[] = '('.$g->lcell('e'.$e.'_cat').'="'.$cats[$c].'")*'.$k->r('e'.$e.'_per');
            }

            return implode('+', $parts);
        };
        foreach (self::CATEGORIES as $c) {
            if (isset($cats[$c])) {
                $g->line('cat_'.$c, $x->t('exp.catTotal', $cats[$c]), fn (K $k) => $catSum($k, $c), ['total' => 'sum']);
            }
        }
        $all = function (K $k, string $suffix) use ($list) {
            return implode('+', array_map(fn ($e) => $k->r('e'.$e.'_'.$suffix), array_keys($list)));
        };
        $g->line('payT', $x->t('exp.payT'), fn (K $k) => $all($k, 'pay'), ['total' => 'sum', 'bold' => true]);
        $g->line('endT', $x->t('exp.endT'), fn (K $k) => $all($k, 'end'), ['bold' => true]);
        $g->line('preT', $x->t('exp.preT'), function (K $k) use ($list, $g) {
            $parts = [];
            foreach ($list as $e => $row) {
                if ($row['relation_name'] === 'one_time_expense') {
                    $parts[] = $k->r('e'.$e.'_pre');
                }
            }

            return $parts === [] ? '0' : implode('+', $parts);
        }, ['note' => $x->t('exp.prepaidNote')]);
        $g->blank();
        $g->section($x->t('exp.toProducts'));
        $g->monthHeader();
        foreach ($m->products as $i => $pr) {
            $g->line('toP'.$i, (string) ($pr['name'] ?? ''), fn (K $k) => $all($k, 'a'.$i), ['total' => 'sum']);
        }
        $sh->freeze(4, Ctx::FIRST);
    }
}
