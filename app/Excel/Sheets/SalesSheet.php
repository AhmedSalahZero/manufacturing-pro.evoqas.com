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

        // Sales channels (local) and export markets the product shares its quantity over. None saved = the old way: one local and one export price.
        $seg = array_values((array) ($pr['segments'] ?? []));
        $chs = array_values(array_filter($seg, fn ($r) => ($r['kind'] ?? '') === 'local'));
        $mks = array_values(array_filter($seg, fn ($r) => ($r['kind'] ?? '') === 'export'));
        $hasSeg = $seg !== [];
        // Only channels (or only markets) saved: the side with none behaves as one implicit channel / market at the product's own price, as in the application.
        if ($hasSeg && $chs === []) {
            $chs = [['kind' => 'local', 'name' => $x->t('sales.implLocal'), 'allocation_percentages' => array_fill(0, $m->Y, 100), 'price_differences' => array_fill(0, $m->Y, 0), 'uses_own_terms' => false, 'implicit' => true]];
        }
        if ($hasSeg && $mks === []) {
            $mks = [['kind' => 'export', 'name' => $x->t('sales.implExport'), 'currency' => '', 'uses_own_terms' => false, 'implicit' => true]];
        }
        $exportPattern = $hasSeg && ! empty($pr['export_seasonality']) ? $pr['export_seasonality'] : null;

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
        if (! $hasSeg || in_array(true, array_column($mks, 'implicit'), true)) {
            $g->yearInput('ep', $x->t('sales.ep'), $m->yearly($pr['export_price_per_unit'] ?? []), 'in_num');
        }
        $tgt = $g->rowOf('tgt');
        $cap = $g->rowOf('cap');
        $locp = $g->rowOf('locp');
        $g->yearCalc('ytq', $x->t('sales.tq'), fn ($y, $c) => "$c$cap*$c$tgt/100", 'num');
        $g->yearCalc('ylq', $x->t('sales.lq'), fn ($y, $c) => "{$c}{$g->rowOf('ytq')}*$c$locp/100", 'num');
        $g->yearCalc('yeq', $x->t('sales.eq'), fn ($y, $c) => "{$c}{$g->rowOf('ytq')}-{$c}{$g->rowOf('ylq')}", 'num');
        if (! $hasSeg) {
            $g->yearCalc('yup', $x->t('sales.up'), function ($y, $c) use ($g) {
                $lq = $g->rowOf('ylq'); $eq = $g->rowOf('yeq'); $lp = $g->rowOf('lp'); $ep = $g->rowOf('ep');

                return "IF($c$lq+$c$eq>0,($c$lq*$c$lp+$c$eq*$c$ep)/($c$lq+$c$eq),0)";
            }, 'num2', $x->t('sales.upNote'));
        }
        $g->blank();

        if ($hasSeg) {
            self::segmentsYearly($x, $g, $pr, $chs, $mks);
        }

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

        $types = ['flat' => $x->t('sales.sFlat'), 'quarterly' => $x->t('sales.sQuarterly'), 'monthly' => $x->t('sales.sMonthly')];
        // One monthly pattern: its type, the quarter and month inputs and the weight of each of the 12 months. $p is '' (local) or 'e' (export).
        $pattern = function (string $p, ?array $seas, string $title) use ($x, $g, $sh, $types): void {
            $g->section($title);
            $r = $g->row;
            $sh->set($r, 1, $x->t('sales.sType'), $x->s('label_i'))->set($r, Ctx::FIRST, $types[$seas['type'] ?? 'flat'] ?? $types['flat'], $x->s('in_list'));
            $sh->list(Sheet::col(Ctx::FIRST).$r, array_values($types));
            $x->cells[$g->key.'.'.$p.'stype'] = $x->pre($g->key).'$'.Sheet::col(Ctx::FIRST).'$'.$r;
            $g->row++;
            $pcts = array_values((array) ($seas['percentages'] ?? []));
            $type = $seas['type'] ?? 'flat';
            $r = $g->row;
            $sh->set($r, 1, $x->t('sales.sQ'), $x->s('label_i'))->set($r, 2, $x->t('sales.sQNote'), $x->s('note'));
            for ($q = 0; $q < 4; $q++) {
                $sh->set($r, Ctx::FIRST + $q, $type === 'quarterly' ? (float) ($pcts[$q] ?? 0) : 0.0, $x->s('in_pct'));
            }
            $g->register($p.'sq', $r);
            $g->row++;
            $r = $g->row;
            $sh->set($r, 1, $x->t('sales.sM'), $x->s('label_i'))->set($r, 2, $x->t('sales.sMNote'), $x->s('note'));
            for ($q = 0; $q < 12; $q++) {
                $sh->set($r, Ctx::FIRST + $q, $type === 'monthly' ? (float) ($pcts[$q] ?? 0) : 0.0, $x->s('in_pct'));
            }
            $g->register($p.'sm', $r);
            $g->row++;
            $r = $g->row;
            $sh->set($r, 1, $x->t('sales.sW'), $x->s('label_i'))->set($r, 2, $x->t('sales.sWNote'), $x->s('note'));
            $stype = $g->lcell($p.'stype');
            for ($q = 0; $q < 12; $q++) {
                $col = Sheet::col(Ctx::FIRST + $q);
                $sq = '$'.Sheet::col(Ctx::FIRST).'$'.$g->rowOf($p.'sq').':$'.Sheet::col(Ctx::FIRST + 3).'$'.$g->rowOf($p.'sq');
                $sm = '$'.Sheet::col(Ctx::FIRST).'$'.$g->rowOf($p.'sm').':$'.Sheet::col(Ctx::FIRST + 11).'$'.$g->rowOf($p.'sm');
                $sh->formula($r, Ctx::FIRST + $q, 'IF('.$stype.'="'.$types['quarterly'].'",INDEX('.$sq.',1,'.intdiv($q, 3).'+1)/3/100,IF('.$stype.'="'.$types['monthly'].'",INDEX('.$sm.',1,'.($q + 1).')/100,1/12))', $x->s('pctf'));
            }
            $g->register($p.'sw', $r);
            $g->row++;
            $g->blank();
        };
        $pattern('', $pr['seasonality'] ?? [], $x->t($exportPattern !== null || ($hasSeg && $mks !== []) ? 'sales.seasonL' : 'sales.season'));
        if ($hasSeg && $mks !== []) {
            // Export sales have their own pattern; a product that saved none follows the local one.
            $pattern('e', $exportPattern ?? ($pr['seasonality'] ?? []), $x->t('sales.seasonE'));
        }

        $g->section($x->t('sales.policies'));
        $g->policyHeader();
        $pol = (array) ($pr['collection_policy_value'] ?? []);
        $g->policyRow('pLF', $x->t('sales.pLF'), (array) ($pol['local'][0] ?? []));
        $g->policyRow('pLL', $x->t('sales.pLL'), (array) ($pol['local'][1] ?? []));
        $g->policyRow('pEF', $x->t('sales.pEF'), (array) ($pol['export'][0] ?? []));
        $g->policyRow('pEL', $x->t('sales.pEL'), (array) ($pol['export'][1] ?? []));
        // A channel or market with its own terms has its own two policy lines.
        foreach ([...$chs, ...$mks] as $n => $row) {
            if (! empty($row['uses_own_terms'])) {
                $own = (array) ($row['collection_policy_value'] ?? []);
                $g->policyRow('pC'.$n.'F', $x->t('sales.pSegF', (string) ($row['name'] ?? '')), (array) ($own[0] ?? []));
                $g->policyRow('pC'.$n.'L', $x->t('sales.pSegL', (string) ($row['name'] ?? '')), (array) ($own[1] ?? $own[0] ?? []));
            }
        }
        $g->blank();

        // ── monthly ──
        $g->section($x->t('sales.monthly'));
        $g->monthHeader();
        $sw = '$'.Sheet::col(Ctx::FIRST).'$'.$g->rowOf('sw').':$'.Sheet::col(Ctx::FIRST + 11).'$'.$g->rowOf('sw');
        $esw = $g->has('esw') ? '$'.Sheet::col(Ctx::FIRST).'$'.$g->rowOf('esw').':$'.Sheet::col(Ctx::FIRST + 11).'$'.$g->rowOf('esw') : $sw;
        $planKeys = $hasSeg ? ['rawW', 'share', 'rawWE', 'shareE'] : ['rawW', 'share'];
        if ($hasSeg) {
            foreach ($chs as $i => $_) {
                $planKeys[] = 'cq'.$i;
            }
            foreach ($mks as $j => $_) {
                $planKeys[] = 'mq'.$j;
            }
            foreach ($chs as $i => $_) {
                $planKeys[] = 'cv'.$i;
            }
            foreach ($mks as $j => $_) {
                $planKeys[] = 'mv'.$j;
            }
        }
        array_push($planKeys, 'lq', 'eq', 'q', 'lv', 'ev', 'v', '_', 'S', 'H', 'ibeg', 'need', 'made', 'avail', 'sold', 'iend', '_', 'S', 'H');
        foreach ($m->rms as $j => $rm) {
            $planKeys[] = 'cons'.$j;
        }
        $planKeys[] = 'consT';
        $g->plan($planKeys);

        $g->line('rawW', $x->t('sales.rawW'), fn (K $k) => "{$k->cal('op')}*IF({$k->cal('idx')}>={$g->lcell('sellIdx')},1,0)*INDEX($sw,1,{$k->cal('mnum')})", ['style' => 'pctf']);
        $g->line('share', $x->t('sales.share'), fn (K $k) => "IF(SUMIF({$x->calRng('yidx')},{$k->cal('yidx')},{$g->rng('rawW')})=0,0,{$k->r('rawW')}/SUMIF({$x->calRng('yidx')},{$k->cal('yidx')},{$g->rng('rawW')}))", ['style' => 'pctf']);
        if ($hasSeg) {
            $g->line('rawWE', $x->t('sales.rawWE'), fn (K $k) => "{$k->cal('op')}*IF({$k->cal('idx')}>={$g->lcell('sellIdx')},1,0)*INDEX($esw,1,{$k->cal('mnum')})", ['style' => 'pctf']);
            $g->line('shareE', $x->t('sales.shareE'), fn (K $k) => "IF(SUMIF({$x->calRng('yidx')},{$k->cal('yidx')},{$g->rng('rawWE')})=0,0,{$k->r('rawWE')}/SUMIF({$x->calRng('yidx')},{$k->cal('yidx')},{$g->rng('rawWE')}))", ['style' => 'pctf']);
            foreach ($chs as $i => $row) {
                $g->line('cq'.$i, $x->t('sales.mSegQ', (string) $row['name']), fn (K $k) => "{$k->r('share')}*{$g->pick('chq'.$i, $k)}", ['total' => 'sum', 'style' => 'num']);
            }
            foreach ($mks as $j => $row) {
                $g->line('mq'.$j, $x->t('sales.mSegQ', (string) $row['name']), fn (K $k) => "{$k->r('shareE')}*{$g->pick('mkq'.$j, $k)}", ['total' => 'sum', 'style' => 'num']);
            }
            foreach ($chs as $i => $row) {
                $g->line('cv'.$i, $x->t('sales.mSegV', (string) $row['name']), fn (K $k) => "{$k->r('cq'.$i)}*{$g->pick('chp'.$i, $k)}", ['total' => 'sum']);
            }
            foreach ($mks as $j => $row) {
                $g->line('mv'.$j, $x->t('sales.mSegV', (string) $row['name']), fn (K $k) => "{$k->r('mq'.$j)}*{$g->pick('mkx'.$j, $k)}", ['total' => 'sum']);
            }
            $sumOf = fn (K $k, string $prefix, array $rows) => $rows === [] ? '0' : implode('+', array_map(fn ($n) => $k->r($prefix.$n), array_keys($rows)));
            $g->line('lq', $x->t('sales.mLq'), fn (K $k) => $sumOf($k, 'cq', $chs), ['total' => 'sum', 'style' => 'num', 'bold' => true]);
            $g->line('eq', $x->t('sales.mEq'), fn (K $k) => $sumOf($k, 'mq', $mks), ['total' => 'sum', 'bold' => true]);
        } else {
            $g->line('lq', $x->t('sales.mLq'), fn (K $k) => "{$k->r('share')}*{$g->pick('ylq', $k)}", ['total' => 'sum', 'style' => 'num']);
            $g->line('eq', $x->t('sales.mEq'), fn (K $k) => "{$k->r('share')}*{$g->pick('yeq', $k)}", ['total' => 'sum']);
        }
        $g->line('q', $x->t('sales.mQ'), fn (K $k) => "{$k->r('lq')}+{$k->r('eq')}", ['total' => 'sum', 'bold' => true]);
        if ($hasSeg) {
            $g->line('lv', $x->t('sales.mLv'), fn (K $k) => $sumOf($k, 'cv', $chs), ['total' => 'sum', 'bold' => true]);
            $g->line('ev', $x->t('sales.mEv'), fn (K $k) => $sumOf($k, 'mv', $mks), ['total' => 'sum', 'bold' => true]);
        } else {
            $g->line('lv', $x->t('sales.mLv'), fn (K $k) => "{$k->r('lq')}*{$g->pick('lp', $k)}", ['total' => 'sum']);
            $g->line('ev', $x->t('sales.mEv'), fn (K $k) => "{$k->r('eq')}*{$g->pick('ep', $k)}", ['total' => 'sum']);
        }
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

        // collection statements: the product's own terms for what has no terms of its own, then one statement for each channel / market that has its own
        $own = [];
        foreach ($chs as $i => $row) {
            if (! empty($row['uses_own_terms'])) {
                $own[] = ['p' => 'lc'.$i, 'base' => 'cv'.$i, 'pol' => 'pC'.$i, 'name' => (string) $row['name'], 'local' => true];
            }
        }
        foreach ($mks as $j => $row) {
            if (! empty($row['uses_own_terms'])) {
                $own[] = ['p' => 'mc'.$j, 'base' => 'mv'.$j, 'pol' => 'pC'.(count($chs) + $j), 'name' => (string) $row['name'], 'local' => false];
            }
        }
        foreach (['l' => 'local', 'e' => 'export'] as $s => $kind) {
            $base = $s === 'l' ? 'lv' : 'ev';
            if ($own !== []) {
                // what is left for the product's terms: the channels (markets) without terms of their own
                $rows = $s === 'l' ? $chs : $mks;
                $prefix = $s === 'l' ? 'cv' : 'mv';
                $left = [];
                foreach ($rows as $n => $row) {
                    if (empty($row['uses_own_terms'])) {
                        $left[] = $prefix.$n;
                    }
                }
                $g->section($x->t('sales.col'.ucfirst($kind)));
                $g->monthHeader();
                $base = $base.'D';
                $g->plan([$base]);
                $g->line($base, $x->t($s === 'l' ? 'sales.cDefLocal' : 'sales.cDefExport'), fn (K $k) => $left === [] ? '0' : implode('+', array_map(fn ($key) => $k->r($key), $left)), ['total' => 'sum']);
                self::collection($x, $g, $s, $kind, $base, false);
            } else {
                self::collection($x, $g, $s, $kind, $base);
            }
        }
        foreach ($own as $o) {
            $g->section($x->t('sales.colSeg', $o['name']));
            $g->monthHeader();
            Streams::build($x, $g, $o['p'].'_', $o['base'], $o['local'] ? $g->lcell('vat') : '0', $o['local'] ? $g->lcell('wh') : '0', $g->key.'.'.$o['pol'].'F', $g->key.'.'.$o['pol'].'L', self::STREAM_LABELS);
            $g->blank();
        }

        $local = array_merge(['l_'], array_map(fn ($o) => $o['p'].'_', array_filter($own, fn ($o) => $o['local'])));
        $export = array_merge(['e_'], array_map(fn ($o) => $o['p'].'_', array_filter($own, fn ($o) => ! $o['local'])));
        $addUp = fn (K $k, array $prefixes, string $suffix) => implode('+', array_map(fn ($p) => $k->r($p.$suffix), $prefixes));
        $g->section($x->t('sales.colTotal'));
        $g->monthHeader();
        $g->plan(['c_vat', 'c_wh', 'c_pay', 'c_end']);
        $g->line('c_vat', $x->t('sales.cVat'), fn (K $k) => $addUp($k, $local, 'vat'), ['total' => 'sum']);
        $g->line('c_wh', $x->t('sales.cWh'), fn (K $k) => $addUp($k, $local, 'cwh'), ['total' => 'sum']);
        $g->line('c_pay', $x->t('sales.cPay'), fn (K $k) => $addUp($k, array_merge($local, $export), 'net'), ['total' => 'sum', 'bold' => true]);
        $g->line('c_end', $x->t('sales.cEnd'), fn (K $k) => $addUp($k, array_merge($local, $export), 'end'), ['bold' => true]);

        $sh->freeze(4, Ctx::FIRST);
        unset($sell);
    }

    /** Label keys of the rows of a collection statement. */
    private const STREAM_LABELS = [
        'base' => 'sales.cBase', 'vat' => 'sales.cVatRow', 'wh' => 'sales.cWhRow', 'av' => 'sales.cAv', 'avF' => 'sales.cAvF', 'avL' => 'sales.cAvL', 'whF' => 'sales.cWhF', 'whL' => 'sales.cWhL',
        'cav' => 'sales.cCav', 'cwh' => 'sales.cCwh', 'net' => 'sales.cNet', 'beg' => 'sales.cBeg', 'due' => 'sales.cDue', 'end' => 'sales.cEnd',
    ];

    private static function collection(Ctx $x, Grid $g, string $s, string $kind, ?string $base = null, bool $heading = true): void
    {
        $vat = $s === 'l' ? $g->lcell('vat') : '0';
        $wh = $s === 'l' ? $g->lcell('wh') : '0';
        if ($heading) {
            $g->section($x->t('sales.col'.ucfirst($kind)));
            $g->monthHeader();
        }
        Streams::build($x, $g, $s.'_', $base ?? ($s === 'l' ? 'lv' : 'ev'), $vat, $wh, $g->key.'.'.($s === 'l' ? 'pLF' : 'pEF'), $g->key.'.'.($s === 'l' ? 'pLL' : 'pEL'), self::STREAM_LABELS);
        $g->blank();
    }

    /**
     * The yearly part of the channels and markets: the shares and price differences (inputs), the first foreign price and its growth (inputs),
     * the exchange rates (from the Exchange rates sheet), and what they give for each year. Same rules as SegmentCalculator.
     *
     * @param  list<array<string,mixed>>  $chs
     * @param  list<array<string,mixed>>  $mks
     */
    private static function segmentsYearly(Ctx $x, Grid $g, array $pr, array $chs, array $mks): void
    {
        $m = $x->m;
        $rowOf = fn (string $key) => $g->rowOf($key);

        if ($chs !== []) {
            $g->section($x->t('sales.segChannels'));
            $g->yearHeader('yhc');
            foreach ($chs as $i => $row) {
                $name = (string) ($row['name'] ?? '');
                $g->yearInput('chs'.$i, $x->t('sales.segShare', $name), $m->yearly($row['allocation_percentages'] ?? []), 'in_pct', $x->t('sales.segShareNote'));
                $g->yearInput('chd'.$i, $x->t('sales.segDiff', $name), $m->yearly($row['price_differences'] ?? []), 'in_pct', $x->t('sales.segDiffNote'));
                $g->yearCalc('chq'.$i, $x->t('sales.segQty', $name), fn ($y, $c) => "{$c}{$rowOf('ylq')}*{$c}{$rowOf('chs'.$i)}/100", 'num');
                $g->yearCalc('chp'.$i, $x->t('sales.segPrice', $name), fn ($y, $c) => "{$c}{$rowOf('lp')}*(1+{$c}{$rowOf('chd'.$i)}/100)", 'num2');
            }
            $g->blank();
        }

        if ($mks !== []) {
            $g->section($x->t('sales.segMarkets'));
            $real = array_filter($mks, fn ($r) => empty($r['implicit']));
            if ($real !== []) {
                $g->scalar('sellY', $x->t('sales.segSellY'), 'MAX(0,YEAR('.$g->lcell('sell').')-YEAR(Z_Start))', 'lock_int', $x->t('sales.segSellYNote'), true);
            }
            foreach ($real as $j => $row) {
                $cur = ModelData::currencyName((string) ($row['currency'] ?? ''));
                $g->scalar('mkf'.$j, $x->t('sales.segFirst', (string) $row['name'], $cur), (float) ($row['first_foreign_price'] ?? 0), 'in_num', $x->t('sales.segFirstNote'));
            }
            $g->yearHeader('yhm');
            $last = $m->Y - 1;
            foreach ($mks as $j => $row) {
                $name = (string) ($row['name'] ?? '');
                if (! empty($row['implicit'])) {
                    $g->yearCalc('mkq'.$j, $x->t('sales.segQty', $name), fn ($y, $c) => "{$c}{$rowOf('yeq')}", 'num');
                    $g->yearCalc('mkx'.$j, $x->t('sales.segXPrice', $name), fn ($y, $c) => "{$c}{$rowOf('ep')}", 'num2');

                    continue;
                }
                $cur = ModelData::currencyName((string) ($row['currency'] ?? ''));
                $fxKey = 'fx.'.($row['currency'] ?? '');
                $g->yearInput('mks'.$j, $x->t('sales.segShareM', $name), $m->yearly($row['allocation_percentages'] ?? []), 'in_pct', $x->t('sales.segShareNote'));
                $g->yearInput('mkg'.$j, $x->t('sales.segGrowth', $name), $m->yearly($row['foreign_growth_rates'] ?? []), 'in_pct', $x->t('sales.segGrowthNote'));
                $g->yearCalc('mkq'.$j, $x->t('sales.segQty', $name), fn ($y, $c) => "{$c}{$rowOf('yeq')}*{$c}{$rowOf('mks'.$j)}/100", 'num');
                // the first selling year carries the typed price, each later year grows from the year before (2 decimals); the extra year repeats the last
                $g->yearCalc('mkp'.$j, $x->t('sales.segForeign', $name, $cur), function ($y, $c, $p) use ($g, $j, $last, $rowOf) {
                    $self = $rowOf('mkp'.$j);
                    if ($y === $last && $y > 0) {
                        return $p.$self;
                    }
                    $grown = $y === 0 ? '0' : "ROUND($p$self*(1+$c{$rowOf('mkg'.$j)}/100),2)";

                    return "IF($y<{$g->lcell('sellY')},0,IF($y={$g->lcell('sellY')},{$g->lcell('mkf'.$j)},$grown))";
                }, 'num2');
                $g->yearCalc('mkx'.$j, $x->t('sales.segXPrice', $name), fn ($y, $c) => "{$c}{$rowOf('mkp'.$j)}*".(isset($x->rows[$fxKey]) ? $x->x($fxKey, $c) : '0'), 'num2');
            }
            $g->blank();
        }

        // totals of the year and the average price that feeds the raw materials
        $sum = fn (string $prefix, array $rows, string $c) => $rows === [] ? '0' : implode('+', array_map(fn ($n) => $c.$g->rowOf($prefix.$n), array_keys($rows)));
        $g->section($x->t('sales.segTotals'));
        $g->yearHeader('yht');
        $g->yearCalc('ylv', $x->t('sales.segLocalValue'), function ($y, $c) use ($g, $chs, $rowOf) {
            $parts = [];
            foreach (array_keys($chs) as $i) {
                $parts[] = "{$c}{$rowOf('chq'.$i)}*{$c}{$rowOf('chp'.$i)}";
            }

            return $parts === [] ? '0' : implode('+', $parts);
        }, 'num');
        $g->yearCalc('yev', $x->t('sales.segExportValue'), function ($y, $c) use ($g, $mks, $rowOf) {
            $parts = [];
            foreach (array_keys($mks) as $j) {
                $parts[] = "{$c}{$rowOf('mkq'.$j)}*{$c}{$rowOf('mkx'.$j)}";
            }

            return $parts === [] ? '0' : implode('+', $parts);
        }, 'num');
        $g->yearCalc('yup', $x->t('sales.up'), fn ($y, $c) => "IF(({$sum('chq', $chs, $c)})+({$sum('mkq', $mks, $c)})>0,({$c}{$rowOf('ylv')}+{$c}{$rowOf('yev')})/(({$sum('chq', $chs, $c)})+({$sum('mkq', $mks, $c)})),0)", 'num2', $x->t('sales.upNote'));
        $g->blank();
    }
}
