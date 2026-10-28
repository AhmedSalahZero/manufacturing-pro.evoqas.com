<?php

namespace App\Excel\Sheets;

use App\Excel\Ctx;
use App\Excel\Grid;
use App\Excel\K;
use App\Excel\ModelData;
use App\Excel\Sheet;

// One sheet per product: the sales plan (inputs of the product page) and everything worked out from it month by month:
// quantities, values, finished-goods stock, raw materials consumed, and the collection statements (local, export, total).
final class SalesSheet
{
    /** Row-key prefix of product number $i (0-based) on its sheet. */
    public static function key(int $i): string
    {
        return 's'.($i + 1);
    }

    public static function build(Ctx $x, int $i): void
    {
        $m = $x->m;
        $pr = $m->products[$i];
        $sk = self::key($i);
        $name = (string) ($pr['name'] ?? '');
        $sh = $x->sheet($sk, $x->t('n.sales', $i + 1).($name !== '' ? ' - '.$name : ''));
        $g = new Grid($x, $sh, $sk);
        $g->title($x->t('sales.title', $name), $x->t('sales.sub'));

        // ── inputs ──
        $g->section($x->t('sales.inputs'));
        $sell = $pr['selling_start_date'] ?? 0;
        $g->scalar('sell', $x->t('sales.sell'), (float) $m->serial((int) $sell), 'in_date', $x->t('sales.sellNote'));
        $g->scalar('sellIdx', $x->t('sales.sellIdx'), "(YEAR({$g->lcell('sell')})-YEAR(Z_Start))*12+MONTH({$g->lcell('sell')})-MONTH(Z_Start)", 'lock_int', null, true);
        $g->scalar('vat', $x->t('sales.vat'), (float) ($pr['vat_rate'] ?? 0), 'in_pct', $x->t('sales.vatNote'));
        $g->scalar('wh', $x->t('sales.wh'), (float) ($pr['withhold_tax_rate'] ?? 0), 'in_pct', $x->t('sales.whNote'));
        $g->scalar('fgDays', $x->t('sales.fgDays'), (float) ($pr['fg_inventory_coverage_days'] ?? 0), 'in_int', $x->t('sales.fgDaysNote'));
        $g->scalar('fgW', $x->t('sales.fgW'), 'INT('.$g->lcell('fgDays').'/30)', 'lock_int', null, true);
        $g->scalar('fgFrac', $x->t('sales.fgFrac'), $g->lcell('fgDays').'/30-'.$g->lcell('fgW'), 'num2', null, true);
        $g->scalar('fgQty', $x->t('sales.fgQty'), (float) ($pr['fg_inventory_quantity'] ?? 0), 'in_num', $x->t('sales.existingOnly'));
        $bd = (array) ($pr['fg_beginning_inventory_breakdowns'] ?? []);
        foreach (['raw_material_value' => 'rm', 'direct_labor_value' => 'dl', 'manufacturing_overheads_value' => 'oh'] as $type => $short) {
            $g->scalar('fgv_'.$short, $x->t('sales.fgv.'.$short), (float) (($bd[$type]['value'] ?? 0)), 'in_num', $x->t('sales.existingOnly'));
        }
        $g->blank();

        $g->section($x->t('sales.yearly'));
        $g->yearHeader();
        $g->yearInput('cap', $x->t('sales.cap'), $m->yearly($pr['max_capacity'] ?? []), 'in_int', $x->t('sales.capNote'));
        $g->yearInput('tgt', $x->t('sales.tgt'), $m->yearly($pr['target_percentages'] ?? []), 'in_pct');
        $g->yearInput('locp', $x->t('sales.locp'), $m->yearly($pr['local_target_quantity_percentages'] ?? []), 'in_pct', $x->t('sales.locpNote'));
        $g->yearInput('lp', $x->t('sales.lp'), $m->yearly($pr['local_price_per_unit'] ?? []), 'in_num');
        $g->yearInput('ep', $x->t('sales.ep'), $m->yearly($pr['export_price_per_unit'] ?? []), 'in_num');
        $tgt = $g->rowOf('tgt');
        $cap = $g->rowOf('cap');
        $locp = $g->rowOf('locp');
        $g->yearCalc('ytq', $x->t('sales.tq'), fn ($y, $c) => "$c$cap*$c$tgt/100", 'num');
        $g->yearCalc('ylq', $x->t('sales.lq'), fn ($y, $c) => "{$c}{$g->rowOf('ytq')}*$c$locp/100", 'num');
        $g->yearCalc('yeq', $x->t('sales.eq'), fn ($y, $c) => "{$c}{$g->rowOf('ytq')}-{$c}{$g->rowOf('ylq')}", 'num');
        $g->yearCalc('yup', $x->t('sales.up'), function ($y, $c) use ($g) {
            $lq = $g->rowOf('ylq'); $eq = $g->rowOf('yeq'); $lp = $g->rowOf('lp'); $ep = $g->rowOf('ep');

            return "IF($c$lq+$c$eq>0,($c$lq*$c$lp+$c$eq*$c$ep)/($c$lq+$c$eq),0)";
        }, 'num2', $x->t('sales.upNote'));
        $g->blank();

        if ($m->rms !== []) {
            $g->section($x->t('sales.recipe'));
            $g->yearHeader('yh2');
            $recipe = [];
            foreach ((array) ($pr['recipe'] ?? []) as $line) {
                $recipe[(int) $line['raw_material_id']] = ModelData::arr($line['percentages'] ?? []);
            }
            foreach ($m->rms as $j => $rm) {
                $g->yearInput('rc'.$j, (string) ($rm['name'] ?? $x->t('rm.item', $j + 1)), $m->yearly($recipe[(int) $rm['id']] ?? []), 'in_pct', $x->t('sales.recipeNote'));
            }
            $g->blank();
        }

        $g->section($x->t('sales.season'));
        $seas = $pr['seasonality'] ?? [];
        $types = ['flat' => $x->t('sales.sFlat'), 'quarterly' => $x->t('sales.sQuarterly'), 'monthly' => $x->t('sales.sMonthly')];
        $r = $g->row;
        $sh->set($r, 1, $x->t('sales.sType'), $x->s('label_i'))->set($r, Ctx::FIRST, $types[$seas['type'] ?? 'flat'] ?? $types['flat'], $x->s('in_list'));
        $sh->list(Sheet::col(Ctx::FIRST).$r, array_values($types));
        $x->cells[$sk.'.stype'] = $x->pre($sk).'$'.Sheet::col(Ctx::FIRST).'$'.$r;
        $g->row++;
        $pcts = array_values((array) ($seas['percentages'] ?? []));
        $type = $seas['type'] ?? 'flat';
        $r = $g->row;
        $sh->set($r, 1, $x->t('sales.sQ'), $x->s('label_i'))->set($r, 2, $x->t('sales.sQNote'), $x->s('note'));
        for ($q = 0; $q < 4; $q++) {
            $sh->set($r, Ctx::FIRST + $q, $type === 'quarterly' ? (float) ($pcts[$q] ?? 0) : 0.0, $x->s('in_pct'));
        }
        $g->register('sq', $r);
        $g->row++;
        $r = $g->row;
        $sh->set($r, 1, $x->t('sales.sM'), $x->s('label_i'))->set($r, 2, $x->t('sales.sMNote'), $x->s('note'));
        for ($q = 0; $q < 12; $q++) {
            $sh->set($r, Ctx::FIRST + $q, $type === 'monthly' ? (float) ($pcts[$q] ?? 0) : 0.0, $x->s('in_pct'));
        }
        $g->register('sm', $r);
        $g->row++;
        $r = $g->row;
        $sh->set($r, 1, $x->t('sales.sW'), $x->s('label_i'))->set($r, 2, $x->t('sales.sWNote'), $x->s('note'));
        $stype = $g->lcell('stype');
        for ($q = 0; $q < 12; $q++) {
            $col = Sheet::col(Ctx::FIRST + $q);
            $sq = '$'.Sheet::col(Ctx::FIRST).'$'.$g->rowOf('sq').':$'.Sheet::col(Ctx::FIRST + 3).'$'.$g->rowOf('sq');
            $sm = '$'.Sheet::col(Ctx::FIRST).'$'.$g->rowOf('sm').':$'.Sheet::col(Ctx::FIRST + 11).'$'.$g->rowOf('sm');
            $sh->formula($r, Ctx::FIRST + $q, 'IF('.$stype.'="'.$types['quarterly'].'",INDEX('.$sq.',1,'.intdiv($q, 3).'+1)/3/100,IF('.$stype.'="'.$types['monthly'].'",INDEX('.$sm.',1,'.($q + 1).')/100,1/12))', $x->s('pctf'));
        }
        $g->register('sw', $r);
        $g->row++;
        $g->blank();

        $g->section($x->t('sales.policies'));
        $g->policyHeader();
        $pol = (array) ($pr['collection_policy_value'] ?? []);
        $g->policyRow('pLF', $x->t('sales.pLF'), (array) ($pol['local'][0] ?? []));
        $g->policyRow('pLL', $x->t('sales.pLL'), (array) ($pol['local'][1] ?? []));
        $g->policyRow('pEF', $x->t('sales.pEF'), (array) ($pol['export'][0] ?? []));
        $g->policyRow('pEL', $x->t('sales.pEL'), (array) ($pol['export'][1] ?? []));
        $g->blank();

        // ── monthly ──
        $g->section($x->t('sales.monthly'));
        $g->monthHeader();
        $sw = '$'.Sheet::col(Ctx::FIRST).'$'.$g->rowOf('sw').':$'.Sheet::col(Ctx::FIRST + 11).'$'.$g->rowOf('sw');
        $planKeys = ['rawW', 'share', 'lq', 'eq', 'q', 'lv', 'ev', 'v', '_', 'S', 'H', 'ibeg', 'need', 'made', 'avail', 'sold', 'iend', '_', 'S', 'H'];
        foreach ($m->rms as $j => $rm) {
            $planKeys[] = 'cons'.$j;
        }
        $planKeys[] = 'consT';
        $g->plan($planKeys);

        $g->line('rawW', $x->t('sales.rawW'), fn (K $k) => "{$k->cal('op')}*IF({$k->cal('idx')}>={$g->lcell('sellIdx')},1,0)*INDEX($sw,1,{$k->cal('mnum')})", ['style' => 'pctf']);
        $g->line('share', $x->t('sales.share'), fn (K $k) => "IF(SUMIF({$x->calRng('yidx')},{$k->cal('yidx')},{$g->rng('rawW')})=0,0,{$k->r('rawW')}/SUMIF({$x->calRng('yidx')},{$k->cal('yidx')},{$g->rng('rawW')}))", ['style' => 'pctf']);
        $g->line('lq', $x->t('sales.mLq'), fn (K $k) => "{$k->r('share')}*{$g->pick('ylq', $k)}", ['total' => 'sum', 'style' => 'num']);
        $g->line('eq', $x->t('sales.mEq'), fn (K $k) => "{$k->r('share')}*{$g->pick('yeq', $k)}", ['total' => 'sum']);
        $g->line('q', $x->t('sales.mQ'), fn (K $k) => "{$k->r('lq')}+{$k->r('eq')}", ['total' => 'sum', 'bold' => true]);
        $g->line('lv', $x->t('sales.mLv'), fn (K $k) => "{$k->r('lq')}*{$g->pick('lp', $k)}", ['total' => 'sum']);
        $g->line('ev', $x->t('sales.mEv'), fn (K $k) => "{$k->r('eq')}*{$g->pick('ep', $k)}", ['total' => 'sum']);
        $g->line('v', $x->t('sales.mV'), fn (K $k) => "{$k->r('lv')}+{$k->r('ev')}", ['total' => 'sum', 'bold' => true]);
        $g->blank();

        $g->section($x->t('sales.fg'));
        $g->monthHeader();
        $idxR = $x->calRng('idx');
        $qR = $g->rng('q');
        $g->line('ibeg', $x->t('sales.iBeg'), fn (K $k) => $k->pr('iend'));
        $g->line('need', $x->t('sales.iNeed'), fn (K $k) => "IF({$k->cal('op')}=1,{$k->r('q')}+SUMPRODUCT(($idxR>{$k->cal('idx')})*($idxR<={$k->cal('idx')}+{$g->lcell('fgW')})*$qR)+{$g->lcell('fgFrac')}*SUMPRODUCT(($idxR={$k->cal('idx')}+{$g->lcell('fgW')}+1)*$qR),0)");
        $g->line('made', $x->t('sales.iMade'), fn (K $k) => "IF({$k->cal('op')}=1,MAX(0,{$k->r('need')}-{$k->r('ibeg')}),0)", ['total' => 'sum', 'bold' => true]);
        $g->line('avail', $x->t('sales.iAvail'), fn (K $k) => "IF({$k->cal('op')}=1,{$k->r('ibeg')}+{$k->r('made')},0)");
        $g->line('sold', $x->t('sales.iSold'), fn (K $k) => "{$k->r('q')}", ['total' => 'sum']);
        $g->line('iend', $x->t('sales.iEnd'), fn (K $k) => "IF({$k->cal('op')}=1,{$k->r('avail')}-{$k->r('sold')},{$k->pr('iend')})", ['before' => 'IF(Z_NewCo=1,0,'.$g->lcell('fgQty').')', 'bold' => true]);
        $g->blank();

        $g->section($x->t('sales.cons'));
        $g->monthHeader();
        foreach ($m->rms as $j => $rm) {
            $g->line('cons'.$j, (string) ($rm['name'] ?? $x->t('rm.item', $j + 1)), fn (K $k) => "IF({$k->cal('op')}=1,{$g->pick('rc'.$j, $k)}/100*{$g->pick('yup', $k)}*{$k->r('made')},0)", ['total' => 'sum']);
        }
        $g->line('consT', $x->t('sales.consT'), fn (K $k) => $m->rms === [] ? '0' : 'SUM('.$k->c.$g->rowOf('cons0').':'.$k->c.$g->rowOf('cons'.(count($m->rms) - 1)).')', ['total' => 'sum', 'bold' => true]);
        $g->blank();

        // collection statements
        foreach (['l' => 'local', 'e' => 'export'] as $s => $kind) {
            self::collection($x, $g, $s, $kind);
        }
        $g->section($x->t('sales.colTotal'));
        $g->monthHeader();
        $g->plan(['c_vat', 'c_wh', 'c_pay', 'c_end']);
        $g->line('c_vat', $x->t('sales.cVat'), fn (K $k) => $k->r('l_vat'), ['total' => 'sum']);
        $g->line('c_wh', $x->t('sales.cWh'), fn (K $k) => $k->r('l_cwh'), ['total' => 'sum']);
        $g->line('c_pay', $x->t('sales.cPay'), fn (K $k) => "{$k->r('l_net')}+{$k->r('e_net')}", ['total' => 'sum', 'bold' => true]);
        $g->line('c_end', $x->t('sales.cEnd'), fn (K $k) => "{$k->r('l_end')}+{$k->r('e_end')}", ['bold' => true]);

        $sh->freeze(4, Ctx::FIRST);
        unset($sell);
    }

    private static function collection(Ctx $x, Grid $g, string $s, string $kind): void
    {
        $vat = $s === 'l' ? $g->lcell('vat') : '0';
        $wh = $s === 'l' ? $g->lcell('wh') : '0';
        $g->section($x->t('sales.col'.ucfirst($kind)));
        $g->monthHeader();
        Streams::build($x, $g, $s.'_', $s === 'l' ? 'lv' : 'ev', $vat, $wh, $g->key.'.'.($s === 'l' ? 'pLF' : 'pEF'), $g->key.'.'.($s === 'l' ? 'pLL' : 'pEL'), [
            'base' => 'sales.cBase', 'vat' => 'sales.cVatRow', 'wh' => 'sales.cWhRow', 'av' => 'sales.cAv', 'avF' => 'sales.cAvF', 'avL' => 'sales.cAvL', 'whF' => 'sales.cWhF', 'whL' => 'sales.cWhL',
            'cav' => 'sales.cCav', 'cwh' => 'sales.cCwh', 'net' => 'sales.cNet', 'beg' => 'sales.cBeg', 'due' => 'sales.cDue', 'end' => 'sales.cEnd',
        ]);
        $g->blank();
    }
}
