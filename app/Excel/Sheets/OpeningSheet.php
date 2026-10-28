<?php

namespace App\Excel\Sheets;

use App\Excel\Ctx;
use App\Excel\Grid;
use App\Excel\K;
use App\Excel\ModelData;
use App\Excel\Sheet;

// The Opening Balances page (existing companies only): what the company already has on day one - cash, customers, debtors, suppliers,
// creditors, loans, long-term liabilities, taxes, equity and the assets it owns - and how each balance is settled month by month.
final class OpeningSheet
{
    public static function build(Ctx $x): void
    {
        $m = $x->m;
        if ($m->new) {
            return;
        }
        $op = $m->opening;
        $sh = $x->sheet('ob', $x->t('n.ob'));
        $g = new Grid($x, $sh, 'ob');
        $g->title($x->t('ob.title'), $x->t('ob.sub'));
        $yes = $x->t('c.yes');
        $no = $x->t('c.no');
        $pre = $x->pre('ob');
        $one = fn (string $k) => (array) (array_values((array) ($op[$k] ?? []))[0] ?? []);
        $series = fn (mixed $v) => array_map('floatval', array_combine(array_map('intval', array_keys(ModelData::arr($v))), array_values(ModelData::arr($v))) ?: []);

        // ── single figures ──
        $g->section($x->t('ob.figures'));
        $cash = $one('cash');
        $tax = $one('taxes');
        $eq = $one('equity');
        $g->scalar('cashAmt', $x->t('ob.cash'), (float) ($cash['cash_and_bank_amount'] ?? 0), 'in_num');
        $g->scalar('vatAmt', $x->t('ob.vat'), (float) ($tax['vat_amount'] ?? 0), 'in_num', $x->t('ob.vatNote'));
        $g->scalar('whAmt', $x->t('ob.wh'), (float) ($tax['credit_withhold_taxes'] ?? 0), 'in_num', $x->t('ob.whNote'));
        $g->scalar('ctAmt', $x->t('ob.ct'), (float) ($tax['corporate_taxes_payable'] ?? 0), 'in_num');
        $g->scalar('paid', $x->t('ob.paid'), (float) ($eq['paid_up_capital_amount'] ?? 0), 'in_num');
        $g->scalar('legal', $x->t('ob.legal'), (float) ($eq['legal_reserve'] ?? 0), 'in_num');
        $g->scalar('retained', $x->t('ob.retained'), (float) ($eq['retained_earnings'] ?? 0), 'in_num');
        $g->blank();

        // ── groups of settled balances ──
        // each: key prefix => [section label, rows, amount column, payload column]
        $groups = [
            'cs' => ['ob.g.cash', [$cash], 'customer_receivable_amount', 'payload'],
            'su' => ['ob.g.suppliers', [$one('suppliers')], 'amount', 'payload'],
            'd' => ['ob.g.debtors', array_values((array) ($op['other_debtors'] ?? [])), 'amount', 'payload'],
            'c' => ['ob.g.creditors', array_values((array) ($op['other_creditors'] ?? [])), 'amount', 'payload'],
            'lt' => ['ob.g.longterm', array_values((array) ($op['other_long_term'] ?? [])), 'amount', 'payload'],
            'n' => ['ob.g.loans', array_values((array) ($op['loans'] ?? [])), 'amount', 'installments'],
        ];
        $counts = [];
        foreach ($groups as $pf => [$label, $rows, $amountCol, $payCol]) {
            $counts[$pf] = count($rows);
            $g->section($x->t($label));
            $g->monthHeader();
            foreach ($rows as $k => $row) {
                $p = $pf.$k.'_';
                $nameRow = $g->row;
                $sh->set($nameRow, 1, $x->t('ob.item'), $x->s('label_i'))->set($nameRow, Ctx::FIRST, (string) ($row['name'] ?? ''), $x->s('in_txt'));
                $g->row++;
                $g->scalar($p.'amt', $x->t('ob.amount'), (float) ($row[$amountCol] ?? 0), 'in_num');
                $set = $g->inputLine($p.'set', $pf === 'n' ? $x->t('ob.instal') : $x->t('ob.settle'), $series($row[$payCol] ?? []));
                $amt = $g->lcell($p.'amt');
                $g->line($p.'end', $x->t('ob.left'), fn (K $kk) => $kk->pr($p.'end').'-N('.$kk->r($p.'set').')', ['before' => $amt, 'bold' => true]);
                if ($pf === 'n') {
                    $g->inputLine($p.'int', $x->t('ob.interest'), $series($row['interests'] ?? []));
                }
                unset($set);
            }
            $g->blank();
        }
        $sumOf = function (string $pf, string $suffix) use ($counts) {
            return function (K $k) use ($pf, $suffix, $counts) {
                $parts = [];
                for ($i = 0; $i < $counts[$pf]; $i++) {
                    $parts[] = 'N('.$k->r($pf.$i.'_'.$suffix).')';
                }

                return $parts === [] ? '0' : implode('+', $parts);
            };
        };

        // ── assets already owned ──
        $g->section($x->t('ob.g.assets'));
        $assets = array_values((array) ($op['fixed_assets'] ?? []));
        $P = count($m->products);
        $meta = [];
        foreach ($assets as $k => $a) {
            $p = 'a'.$k.'_';
            $g->scalar($p.'name', $x->t('ob.item'), (string) ($a['name'] ?? ''), 'in_txt');
            $g->scalar($p.'gross', $x->t('ob.gross'), (float) ($a['gross_amount'] ?? 0), 'in_num');
            $g->scalar($p.'acc0', $x->t('ob.acc0'), (float) ($a['accumulated_depreciation'] ?? 0), 'in_num');
            $g->scalar($p.'mon', $x->t('ob.mon'), (float) ($a['monthly_depreciation'] ?? 0), 'in_num');
            $g->scalar($p.'cnt', $x->t('ob.cnt'), (float) ($a['monthly_counts'] ?? 0), 'in_int', $x->t('ob.cntNote'));
            $g->scalar($p.'admin', $x->t('fa.admin'), (float) ($a['admin_depreciation_percentage'] ?? 0), 'in_pct', $x->t('fa.adminNote'));
            $g->scalar($p.'mfg', $x->t('fa.mfg'), '100-'.$g->lcell($p.'admin'), 'pct', null, true);
            $r = $g->row;
            $byRev = (bool) ($a['is_as_revenue_percentages'] ?? true);
            $sh->set($r, 1, $x->t('mp.byRevenue'), $x->s('label_i'))->set($r, 5, $byRev ? $yes : $no, $x->s('in_list'));
            $sh->list('E'.$r, [$yes, $no]);
            $x->cells['ob.'.$p.'rev'] = $pre.'$E$'.$r;
            $g->row++;
            $alloc = (array) ($a['product_allocations'] ?? []);
            $first = $g->row;
            foreach ($m->products as $i => $pr) {
                $r = $g->row;
                $sh->set($r, 1, $x->t('fa.shareOf', (string) ($pr['name'] ?? '')), $x->s('label_i'))->set($r, 5, (float) ($alloc[$pr['id']] ?? 0), $x->s('in_pct'));
                $sh->formula($r, 7, $x->name(SalesSheet::key($i).'.sellIdx'), $x->s('flag'));
                $g->row++;
            }
            $meta[$k] = [$first, $g->row - 1];
        }
        if ($assets !== []) {
            $g->monthHeader();
        }
        foreach ($assets as $k => $a) {
            $p = 'a'.$k.'_';
            $L = fn (string $key) => $g->lcell($p.$key);
            [$first, $last] = $meta[$k];
            $g->line($p.'dep', $x->t('ob.dep', (string) ($a['name'] ?? '')), fn (K $kk) => 'IF(AND('.$kk->cal('op').'=1,'.$kk->cal('oppos').'<'.$L('cnt').'),'.$L('mon').',0)', ['total' => 'sum', 'bold' => true]);
            $g->line($p.'adm', $x->t('ob.adm'), fn (K $kk) => $L('admin').'/100*'.$kk->r($p.'dep'), ['total' => 'sum']);
            $g->line($p.'mf', $x->t('ob.mf'), fn (K $kk) => $L('mfg').'/100*'.$kk->r($p.'dep'), ['total' => 'sum']);
            $g->line($p.'acc', $x->t('ob.acc'), fn (K $kk) => $kk->pr($p.'acc').'+'.$kk->r($p.'adm').'+'.$kk->r($p.'mf'), ['before' => $L('acc0')]);
            $g->line($p.'nbv', $x->t('ob.nbv'), fn (K $kk) => $L('gross').'-'.$kk->r($p.'acc'), ['bold' => true, 'before' => $L('gross').'-'.$L('acc0')]);
            foreach ($m->products as $i => $pr) {
                $g->line($p.'p'.$i, $x->t('ob.toProduct', (string) ($pr['name'] ?? '')), function (K $kk) use ($x, $p, $i, $first, $last, $yes, $L) {
                    $range = fn (string $c) => '$'.$c.'$'.$first.':$'.$c.'$'.$last;
                    $row = $first + $i;
                    $typed = ShareSheet::typed($kk, '$E$'.$row, '$G$'.$row, $range('E'), $range('G'));

                    return 'IF('.$L('rev').'="'.$yes.'",'.$x->x('share.rs'.$i, $kk->c).",$typed)*".$kk->r($p.'mf');
                }, ['total' => 'sum']);
            }
        }
        $g->blank();

        // ── totals used by the statements ──
        $g->section($x->t('ob.totals'));
        $g->monthHeader();
        $zero = fn (K $kk) => '0';
        $tot = fn (string $key, string $label, callable $f, bool $sum = true) => $g->line($key, $label, $f, $sum ? ['total' => 'sum'] : ['bold' => true]);
        $tot('t_rcvSet', $x->t('ob.t.rcvSet'), $sumOf('cs', 'set'));
        $tot('t_rcvEnd', $x->t('ob.t.rcvEnd'), $sumOf('cs', 'end'), false);
        $tot('t_supSet', $x->t('ob.t.supSet'), $sumOf('su', 'set'));
        $tot('t_supEnd', $x->t('ob.t.supEnd'), $sumOf('su', 'end'), false);
        $tot('t_debSet', $x->t('ob.t.debSet'), $sumOf('d', 'set'));
        $tot('t_debEnd', $x->t('ob.t.debEnd'), $sumOf('d', 'end'), false);
        $tot('t_creSet', $x->t('ob.t.creSet'), $sumOf('c', 'set'));
        $tot('t_creEnd', $x->t('ob.t.creEnd'), $sumOf('c', 'end'), false);
        $tot('t_ltSet', $x->t('ob.t.ltSet'), $sumOf('lt', 'set'));
        $tot('t_ltEnd', $x->t('ob.t.ltEnd'), $sumOf('lt', 'end'), false);
        $tot('t_loanInst', $x->t('ob.t.loanInst'), $sumOf('n', 'set'));
        $tot('t_loanInt', $x->t('ob.t.loanInt'), $sumOf('n', 'int'));
        $tot('t_loanEnd', $x->t('ob.t.loanEnd'), $sumOf('n', 'end'), false);
        $aSum = function (string $suffix) use ($assets) {
            return function (K $kk) use ($assets, $suffix) {
                $parts = [];
                foreach ($assets as $k => $_) {
                    $parts[] = $kk->r('a'.$k.'_'.$suffix);
                }

                return $parts === [] ? '0' : implode('+', $parts);
            };
        };
        $tot('t_adm', $x->t('ob.t.adm'), $aSum('adm'));
        $tot('t_nbv', $x->t('ob.t.nbv'), $aSum('nbv'), false);
        foreach ($m->products as $i => $pr) {
            $tot('t_p'.$i, $x->t('ob.t.toProduct', (string) ($pr['name'] ?? '')), $aSum('p'.$i));
        }
        $g->blank();

        // ── do the balances balance? ──
        $g->section($x->t('ob.check'));
        $inv = [];
        foreach ($m->products as $i => $_) {
            $k = SalesSheet::key($i);
            $inv[] = $x->name($k.'.fgv_rm').'+'.$x->name($k.'.fgv_dl').'+'.$x->name($k.'.fgv_oh');
        }
        foreach ($m->rms as $j => $_) {
            $inv[] = $x->name('rm.r'.$j.'_beg');
        }
        $netFa = [];
        foreach ($assets as $k => $_) {
            $netFa[] = $g->lcell('a'.$k.'_gross').'-'.$g->lcell('a'.$k.'_acc0');
        }
        $sumCells = function (string $pf) use ($counts, $g) {
            $parts = [];
            for ($i = 0; $i < $counts[$pf]; $i++) {
                $parts[] = $g->lcell($pf.$i.'_'.($pf === 'cs' ? 'amt' : 'amt'));
            }

            return $parts === [] ? '0' : implode('+', $parts);
        };
        $g->scalar('chkA', $x->t('ob.chkA'), '('.($netFa === [] ? '0' : implode('+', $netFa)).')+'.$g->lcell('cashAmt').'+'.$sumCells('cs').'+('.($inv === [] ? '0' : implode('+', $inv)).')+'.$sumCells('d'), 'num', null, true);
        $g->scalar('chkL', $x->t('ob.chkL'), $sumCells('su').'+'.$sumCells('c').'+'.$g->lcell('vatAmt').'+'.$g->lcell('whAmt').'+'.$g->lcell('ctAmt').'+'.$sumCells('n').'+'.$sumCells('lt').'+'.$g->lcell('paid').'+'.$g->lcell('legal').'+'.$g->lcell('retained'), 'num', null, true);
        $g->scalar('chkD', $x->t('ob.chkD'), $g->lcell('chkL').'-'.$g->lcell('chkA'), 'num', $x->t('ob.chkNote'), true);
        $sh->conditional('E'.($g->row - 1), 'ABS(E'.($g->row - 1).')>=0.01', 0);
        $sh->freeze(4, Ctx::FIRST);
    }
}
