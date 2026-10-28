<?php

namespace App\Excel\Sheets;

use App\Excel\Ctx;
use App\Excel\Grid;
use App\Excel\K;
use App\Excel\ModelData;
use App\Excel\Sheet;

// The Manpower Plan: positions (type, people already employed, net monthly salary), the people hired each month, and what it costs:
// headcount, salaries paid, the employer's cost, the tax and social insurance owed, and how direct labour and the staff of the
// manufacturing overheads are shared between the products.
final class ManpowerSheet
{
    public const TYPES = ['direct_labor', 'manufacturing_overheads', 'operational_salaries', 'sales', 'marketing', 'general'];

    /** @return array<string,string> type => the word shown in the sheet */
    public static function typeNames(Ctx $x): array
    {
        $out = [];
        foreach (self::TYPES as $t) {
            $out[$t] = $x->t('mp.t.'.$t);
        }

        return $out;
    }

    public static function build(Ctx $x): void
    {
        $m = $x->m;
        $staff = $m->staff;
        $P = count($m->products);
        $sh = $x->sheet('mp', $x->t('n.mp'));
        $g = new Grid($x, $sh, 'mp');
        $g->title($x->t('mp.title'), $x->t('mp.sub'));
        $types = self::typeNames($x);
        $yes = $x->t('c.yes');
        $no = $x->t('c.no');

        // ── positions table ──
        $g->section($x->t('mp.positions'));
        $r = $g->row;
        foreach ([1 => $x->t('mp.name'), 5 => $x->t('mp.type'), 6 => $x->t('mp.existing'), 7 => $x->t('mp.salary')] as $c => $t) {
            $sh->set($r, $c, $t, $x->s($c === 1 ? 'hdr_l' : 'hdr'));
        }
        foreach ([2, 3, 4] as $c) {
            $sh->set($r, $c, '', $x->s('hdr'));
        }
        $sh->height($r, 32);
        $g->row++;
        $sh->set($g->row, 1, '', 0);
        foreach ($staff as $j => $row) {
            $r = $g->row;
            $sh->set($r, 1, (string) ($row['name'] ?? ''), $x->s('in_txt'));
            $sh->set($r, 5, $types[$row['type']] ?? $types['general'], $x->s('in_list'));
            $sh->list(Sheet::col(5).$r, array_values($types));
            $sh->set($r, 6, (float) ($row['existing_count'] ?? 0), $x->s('in_int'));
            $sh->set($r, 7, (float) ($row['avg_salary'] ?? 0), $x->s('in_num'));
            $pre = $x->pre('mp');
            $x->cells['mp.p'.$j.'.type'] = $pre.'$E$'.$r;
            $x->cells['mp.p'.$j.'.existing'] = $pre.'$F$'.$r;
            $x->cells['mp.p'.$j.'.salary'] = $pre.'$G$'.$r;
            $g->row++;
        }
        $g->blank();

        // ── sharing between products ──
        $g->section($x->t('mp.sharing'));
        $r = $g->row;
        foreach ([1 => $x->t('mp.product'), 5 => $x->t('mp.t.direct_labor'), 6 => $x->t('mp.t.manufacturing_overheads'), 7 => $x->t('mp.sellIdx')] as $c => $t) {
            $sh->set($r, $c, $t, $x->s($c === 1 ? 'hdr_l' : 'hdr'));
        }
        foreach ([2, 3, 4] as $c) {
            $sh->set($r, $c, '', $x->s('hdr'));
        }
        $sh->height($r, 32);
        $g->row++;
        $saved = (array) ($m->p['manpower_allocations'] ?? []);
        $flags = (array) ($m->p['manpower_is_as_revenue_percentages'] ?? []);
        $r = $g->row;
        $sh->set($r, 1, $x->t('mp.byRevenue'), $x->s('label_b'));
        foreach (['direct_labor' => 5, 'manufacturing_overheads' => 6] as $type => $c) {
            $isRev = (bool) ($flags[$type] ?? true);
            $sh->set($r, $c, $isRev ? $yes : $no, $x->s('in_list'));
            $sh->list(Sheet::col($c).$r, [$yes, $no]);
            $x->cells['mp.rev.'.$type] = $x->pre('mp').'$'.Sheet::col($c).'$'.$r;
        }
        $g->row++;
        $first = $g->row;
        foreach ($m->products as $i => $pr) {
            $r = $g->row;
            $sh->set($r, 1, (string) ($pr['name'] ?? ''), $x->s('label_i'));
            foreach (['direct_labor' => 5, 'manufacturing_overheads' => 6] as $type => $c) {
                $typed = (array) ($saved[$type] ?? []);
                $pct = $typed !== [] ? (float) ($typed[$pr['id']] ?? 0) : round(100 / max(1, $P), 4);
                $sh->set($r, $c, $pct, $x->s('in_pct'));
            }
            $sh->formula($r, 7, $x->name(SalesSheet::key($i).'.sellIdx'), $x->s('flag'));
            $g->row++;
        }
        $lastRow = $g->row - 1;
        $rangeOf = fn (string $col) => '$'.$col.'$'.$first.':$'.$col.'$'.$lastRow;
        $g->blank();

        // ── hirings ──
        $g->section($x->t('mp.hirings'));
        $g->monthHeader();
        foreach ($staff as $j => $row) {
            $g->inputLine('hire'.$j, (string) ($row['name'] ?? ''), array_map('floatval', ModelData::arr($row['hirings'] ?? [])), 'in_int', $x->t('mp.hireNote'));
        }
        $g->blank();

        // ── results per position ──
        $keys = [];
        foreach ($staff as $j => $_) {
            array_push($keys, 'S', 'H', 'n'.$j, 'sal'.$j, 'pay'.$j, 'exp'.$j, 'lev'.$j, 'lbeg'.$j, 'ldue'.$j, 'lpay'.$j, 'lend'.$j, '_');
        }
        $levy = '((Z_SalTax+Z_SocIns)/100)';
        $g->plan($keys);
        foreach ($staff as $j => $row) {
            $g->section($x->t('mp.block', $j + 1, (string) ($row['name'] ?? '')));
            $g->monthHeader();
            $g->line('n'.$j, $x->t('mp.count'), fn (K $k) => "IF({$k->cal('idx')}>Z_ExtEnd,0,{$k->pr('n'.$j)}+{$k->cal('op')}*N({$k->r('hire'.$j)}))", ['before' => 'IF(Z_NewCo=1,0,'.$x->name('mp.p'.$j.'.existing').')', 'bold' => true]);
            $g->line('sal'.$j, $x->t('mp.salary'), fn (K $k) => $x->name('mp.p'.$j.'.salary')."*(1+Z_SalInc/100)^INT({$k->cal('oppos')}/12)", ['style' => 'num2']);
            $g->line('pay'.$j, $x->t('mp.pay'), fn (K $k) => "{$k->cal('op')}*{$k->r('sal'.$j)}*{$k->r('n'.$j)}", ['total' => 'sum']);
            $g->line('exp'.$j, $x->t('mp.exp'), fn (K $k) => "IF($levy>=1,0,{$k->r('pay'.$j)}/(1-$levy))", ['total' => 'sum', 'bold' => true]);
            $g->line('lev'.$j, $x->t('mp.lev'), fn (K $k) => "{$k->r('exp'.$j)}*$levy", ['total' => 'sum']);
            $g->line('lbeg'.$j, $x->t('mp.lbeg'), fn (K $k) => $k->pr('lend'.$j));
            $g->line('ldue'.$j, $x->t('mp.ldue'), fn (K $k) => "{$k->r('lbeg'.$j)}+{$k->r('lev'.$j)}");
            $g->line('lpay'.$j, $x->t('mp.lpay'), fn (K $k) => $k->pr('lev'.$j), ['total' => 'sum', 'note' => $x->t('mp.lpayNote')]);
            $g->line('lend'.$j, $x->t('mp.lend'), fn (K $k) => "{$k->r('ldue'.$j)}-{$k->r('lpay'.$j)}", ['bold' => true]);
            $g->blank();
        }

        // ── totals by type, and the share of each product ──
        $g->section($x->t('mp.totals'));
        $g->monthHeader();
        $tot = ['cdl', 'coh', 'csales', 'cmkt', 'cgen', 'payT', 'lpayT', 'lendT'];
        $planned = $tot;
        $planned[] = '_';
        $planned[] = 'S';
        $planned[] = 'H';
        foreach ($m->products as $i => $_) {
            $planned[] = 'adl'.$i;
        }
        foreach ($m->products as $i => $_) {
            $planned[] = 'aoh'.$i;
        }
        $g->plan($planned);
        $sum = function (K $k, array $sel) use ($staff, $x, $types) {
            $parts = [];
            foreach ($staff as $j => $_) {
                $cond = [];
                foreach ($sel as $t) {
                    $cond[] = $x->name('mp.p'.$j.'.type').'="'.str_replace('"', '""', $types[$t]).'"';
                }
                $parts[] = '(OR('.implode(',', $cond).')*'.$k->r('exp'.$j).')';
            }

            return $parts === [] ? '0' : implode('+', $parts);
        };
        $g->line('cdl', $x->t('mp.cdl'), fn (K $k) => $sum($k, ['direct_labor']), ['total' => 'sum']);
        $g->line('coh', $x->t('mp.coh'), fn (K $k) => $sum($k, ['manufacturing_overheads']), ['total' => 'sum']);
        $g->line('csales', $x->t('mp.csales'), fn (K $k) => $sum($k, ['sales']), ['total' => 'sum']);
        $g->line('cmkt', $x->t('mp.cmkt'), fn (K $k) => $sum($k, ['marketing']), ['total' => 'sum']);
        $g->line('cgen', $x->t('mp.cgen'), fn (K $k) => $sum($k, ['general', 'operational_salaries']), ['total' => 'sum']);
        $rowsSum = function (K $k, string $prefix) use ($staff, $g) {
            return $staff === [] ? '0' : implode('+', array_map(fn ($j) => $k->r($prefix.$j), array_keys($staff)));
        };
        $g->line('payT', $x->t('mp.payT'), fn (K $k) => $rowsSum($k, 'pay'), ['total' => 'sum', 'bold' => true]);
        $g->line('lpayT', $x->t('mp.lpayT'), fn (K $k) => $rowsSum($k, 'lpay'), ['total' => 'sum']);
        $g->line('lendT', $x->t('mp.lendT'), fn (K $k) => $rowsSum($k, 'lend'));
        $g->blank();
        $g->section($x->t('mp.alloc'));
        $g->monthHeader();
        foreach (['direct_labor' => ['adl', 'cdl', 'E'], 'manufacturing_overheads' => ['aoh', 'coh', 'F']] as $type => [$pfx, $cost, $col]) {
            foreach ($m->products as $i => $pr) {
                $g->line($pfx.$i, $x->t('mp.allocRow', $types[$type], (string) ($pr['name'] ?? '')), function (K $k) use ($x, $i, $type, $cost, $col, $rangeOf, $first) {
                    $r = $first + $i;
                    $typed = ShareSheet::typed($k, '$'.$col.'$'.$r, '$G$'.$r, $rangeOf($col), $rangeOf('G'));

                    return "IF(".$x->name('mp.rev.'.$type).'="'.$x->t('c.yes').'",'.$x->x('share.rs'.$i, $k->c).",$typed)*{$k->r($cost)}";
                }, ['total' => 'sum']);
            }
        }
        $sh->freeze(4, Ctx::FIRST);
    }
}
