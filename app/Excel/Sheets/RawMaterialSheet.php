<?php

namespace App\Excel\Sheets;

use App\Excel\Ctx;
use App\Excel\Grid;
use App\Excel\K;
use App\Excel\Sheet;

// The raw materials page: for every material the stock rule, the VAT / withholding and payment terms (inputs), then month by month what the
// products consume, what must be bought, what is paid to the supplier and the withholding tax credit.
final class RawMaterialSheet
{
    public static function build(Ctx $x): void
    {
        $m = $x->m;
        if ($m->rms === []) {
            return;
        }
        $sh = $x->sheet('rm', $x->t('n.rm'));
        $g = new Grid($x, $sh, 'rm');
        $g->title($x->t('rm.title'), $x->t('rm.sub'));

        foreach ($m->rms as $j => $rm) {
            $p = 'r'.$j.'_';
            $name = (string) ($rm['name'] ?? '');
            $g->section($x->t('rm.block', $j + 1, $name));
            $g->scalar($p.'days', $x->t('rm.days'), (float) ($rm['rm_inventory_coverage_days'] ?? 0), 'in_int', $x->t('rm.daysNote'));
            $g->scalar($p.'W', $x->t('rm.W'), 'INT('.$g->lcell($p.'days').'/30)', 'lock_int', null, true);
            $g->scalar($p.'frac', $x->t('rm.frac'), $g->lcell($p.'days').'/30-'.$g->lcell($p.'W'), 'num2', null, true);
            $g->scalar($p.'beg', $x->t('rm.beg'), (float) ($rm['beginning_inventory_value'] ?? 0), 'in_num', $x->t('sales.existingOnly'));
            $g->scalar($p.'vat', $x->t('rm.vat'), (float) ($rm['vat_rate'] ?? 0), 'in_pct');
            $g->scalar($p.'wh', $x->t('rm.wh'), (float) ($rm['withhold_tax_rate'] ?? 0), 'in_pct');
            $g->policyHeader();
            $terms = (array) ($rm['collection_policy_value'] ?? []);
            $g->policyRow($p.'pF', $x->t('rm.pF'), (array) ($terms[0] ?? []));
            $g->policyRow($p.'pL', $x->t('rm.pL'), (array) ($terms[1] ?? []));
            $g->blank();

            $g->section($x->t('rm.stock', $name));
            $g->monthHeader();
            $g->plan([$p.'use', $p.'ibeg', $p.'need', $p.'made', $p.'iend', $p.'iendR']);
            $g->line($p.'use', $x->t('rm.use'), function (K $k) use ($m, $j) {
                $parts = [];
                foreach ($m->products as $i => $_) {
                    $parts[] = $k->x(SalesSheet::key($i).'.cons'.$j);
                }

                return $parts === [] ? '0' : implode('+', $parts);
            }, ['total' => 'sum', 'bold' => true]);
            $idxR = $x->calRng('idx');
            $useR = $g->rng($p.'use');
            $g->line($p.'ibeg', $x->t('rm.ibeg'), fn (K $k) => $k->pr($p.'iend'));
            $g->line($p.'need', $x->t('rm.need'), fn (K $k) => "IF({$k->cal('op')}=1,{$k->r($p.'use')}+SUMPRODUCT(($idxR>{$k->cal('idx')})*($idxR<={$k->cal('idx')}+{$g->lcell($p.'W')})*$useR)+{$g->lcell($p.'frac')}*SUMPRODUCT(($idxR={$k->cal('idx')}+{$g->lcell($p.'W')}+1)*$useR),0)");
            $g->line($p.'made', $x->t('rm.made'), fn (K $k) => "IF({$k->cal('op')}=1,MAX(0,{$k->r($p.'need')}-{$k->r($p.'ibeg')}),0)", ['total' => 'sum', 'bold' => true]);
            $g->line($p.'iend', $x->t('rm.iend'), fn (K $k) => "IF({$k->cal('op')}=1,{$k->r($p.'ibeg')}+{$k->r($p.'made')}-{$k->r($p.'use')},{$k->pr($p.'iend')})", ['before' => 'IF(Z_NewCo=1,0,'.$g->lcell($p.'beg').')', 'bold' => true]);
            $g->line($p.'iendR', $x->t('rm.iendR'), fn (K $k) => "{$k->cal('op')}*{$k->r($p.'iend')}", ['note' => $x->t('rm.iendRNote')]);
            $g->blank();

            $g->section($x->t('rm.pay', $name));
            $g->monthHeader();
            Streams::build($x, $g, $p, $p.'made', $g->lcell($p.'vat'), $g->lcell($p.'wh'), $g->key.'.'.$p.'pF', $g->key.'.'.$p.'pL', [
                'base' => 'rm.pBase', 'vat' => 'rm.pVat', 'wh' => 'rm.pWh', 'av' => 'rm.pAv', 'avF' => 'rm.pAvF', 'avL' => 'rm.pAvL', 'whF' => 'rm.pWhF', 'whL' => 'rm.pWhL',
                'cav' => 'rm.pCav', 'cwh' => 'rm.pCwh', 'net' => 'rm.pNet', 'beg' => 'rm.pBeg', 'due' => 'rm.pDue', 'end' => 'rm.pEnd',
            ]);
            $g->blank();

            $g->section($x->t('rm.credit', $name));
            $g->monthHeader();
            $g->plan([$p.'wbeg', $p.'wadd', $p.'wdue', $p.'wpay', $p.'wend']);
            $g->line($p.'wbeg', $x->t('rm.wBeg'), fn (K $k) => $k->pr($p.'wend'));
            $g->line($p.'wadd', $x->t('rm.wAdd'), fn (K $k) => $k->r($p.'cwh'), ['total' => 'sum']);
            $g->line($p.'wdue', $x->t('rm.wDue'), fn (K $k) => "{$k->cal('op')}*({$k->r($p.'wbeg')}+{$k->r($p.'wadd')})");
            $g->line($p.'wpay', $x->t('rm.wPay'), fn (K $k) => "{$k->cal('op')}*IF(OR({$k->cal('mnum')}=1,{$k->cal('mnum')}=4,{$k->cal('mnum')}=7,{$k->cal('mnum')}=10),{$k->pr($p.'wdue')},0)", ['total' => 'sum', 'note' => $x->t('rm.wPayNote')]);
            $g->line($p.'wend', $x->t('rm.wEnd'), fn (K $k) => "{$k->r($p.'wdue')}-{$k->r($p.'wpay')}", ['bold' => true]);
            $g->blank(2);
        }
        $sh->freeze(4, Ctx::FIRST);
    }
}
