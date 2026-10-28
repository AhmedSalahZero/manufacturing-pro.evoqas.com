<?php

namespace App\Excel\Sheets;

use App\Excel\Ctx;
use App\Excel\Grid;
use App\Excel\K;
use App\Excel\Sheet;

// The Fixed Assets page: every asset (cost, execution period, depreciation, replacement, payment terms, equity / loan funding) and, month by month,
// the spending, what is paid, the owners' equity and the bank loan (drawdown and repayment schedule), the asset under construction, the
// depreciation and what is still owed to the supplier. Same rules as FixedAssetCalculator and LoanCalculator.
final class FixedAssetSheet
{
    public const TERMS = ['cash', 'customize', 'installment'];
    public const INTERVALS = ['monthly', 'quarterly', 'semi-annually', 'annually'];
    /** Rows of the loan repayment table (one per instalment period, so up to 20 years of monthly instalments). */
    public const PERIODS = 241;

    public static function build(Ctx $x): void
    {
        $m = $x->m;
        if ($m->assets === []) {
            return;
        }
        $sh = $x->sheet('fa', $x->t('n.fa'));
        $g = new Grid($x, $sh, 'fa');
        $g->title($x->t('fa.title'), $x->t('fa.sub'));
        $terms = [];
        foreach (self::TERMS as $t) {
            $terms[$t] = $x->t('fa.terms.'.$t);
        }
        $ints = [];
        foreach (self::INTERVALS as $t) {
            $ints[$t] = $x->t('fa.int.'.$t);
        }
        $yes = $x->t('c.yes');
        $no = $x->t('c.no');
        $pre = $x->pre('fa');
        $idxR = $x->calRng('idx');
        $D = $m->D;
        $firstDate = 'DATE(YEAR(Z_Start),MONTH(Z_Start),1)';

        foreach ($m->assets as $e => $a) {
            $p = 'f'.$e.'_';
            $L = fn (string $key) => $g->lcell($p.$key);
            $name = (string) ($a['name'] ?? '');
            $g->section($x->t('fa.block', $e + 1, $name));
            $sc = fn (string $key, string $label, mixed $value, string $style, ?string $note = null, bool $f = false) => $g->scalar($p.$key, $label, $value, $style, $note, $f);
            $list = function (string $key, string $label, string $current, array $options) use ($g, $x, $sh, $p, $pre) {
                $r = $g->row;
                $sh->set($r, 1, $label, $x->s('label_i'))->set($r, Ctx::FIRST, $current, $x->s('in_list'));
                $sh->list(Sheet::col(Ctx::FIRST).$r, array_values($options));
                $x->cells[$g->key.'.'.$p.$key] = $pre.'$'.Sheet::col(Ctx::FIRST).'$'.$r;
                $g->row++;
            };
            $dateIdx = fn (string $cell) => "(YEAR($cell)-YEAR(Z_Start))*12+MONTH($cell)-MONTH(Z_Start)";

            $sc('name', $x->t('fa.name'), $name, 'in_txt');
            $sc('counts', $x->t('fa.counts'), (float) ($a['counts'] ?? 1), 'in_num');
            $sc('amount', $x->t('fa.amount'), (float) ($a['amount'] ?? 0), 'in_num', $x->t('fa.amountNote'));
            $sc('total', $x->t('fa.total'), $L('counts').'*'.$L('amount'), 'num', null, true);
            $sc('start', $x->t('fa.start'), (float) $m->serial(min((int) $a['start_date'], $D - 1)), 'in_date', $x->t('fa.startNote'));
            $sc('sIdx', $x->t('fa.sIdx'), $dateIdx($L('start')), 'lock_int', null, true);
            $sc('end', $x->t('fa.end'), (float) $m->serial(min((int) $a['end_date'], $D - 1)), 'in_date');
            $sc('eIdx', $x->t('fa.eIdx'), $dateIdx($L('end')), 'lock_int', null, true);
            $sc('dur', $x->t('fa.dur'), 'IF('.$L('eIdx').'='.$L('sIdx').',0,'.$L('eIdx').'-'.$L('sIdx').'+1)', 'lock_int', $x->t('fa.durNote'), true);
            $sc('depYears', $x->t('fa.depYears'), (float) ($a['depreciation_duration'] ?? 0), 'in_int', $x->t('fa.years'));
            $sc('admin', $x->t('fa.admin'), (float) ($a['admin_depreciation_percentage'] ?? 0), 'in_pct', $x->t('fa.adminNote'));
            $sc('mfg', $x->t('fa.mfg'), '100-'.$L('admin'), 'pct', null, true);
            $sc('replRate', $x->t('fa.replRate'), (float) ($a['replacement_cost_rate'] ?? 0), 'in_pct', $x->t('fa.replRateNote'));
            $sc('replYears', $x->t('fa.replYears'), (float) ($a['replacement_cost_interval'] ?? 0), 'in_int', $x->t('fa.years'));

            // payment terms
            $t = (string) (($a['payment_terms'] ?? '') ?: 'cash');
            $list('terms', $x->t('fa.payTerms'), $terms[$t] ?? $terms['cash'], $terms);
            $policy = [];
            $ft = (array) ($a['from_total_or_executions'] ?? []);
            foreach ((array) ($a['custom_collection_policy'] ?? []) as $days => $rate) {
                $policy[] = [(int) $days, (float) $rate, ! empty($ft[$days])];
            }
            $lines = max(4, count($policy));
            $r = $g->row;
            $sh->set($r, 1, $x->t('fa.custom'), $x->s('label_b'))->set($r, 5, $x->t('exp.days'), $x->s('hdr'))->set($r, 6, $x->t('exp.rate'), $x->s('hdr'))->set($r, 7, $x->t('fa.fromTotal'), $x->s('hdr'))->set($r, 8, $x->t('pol.lag1'), $x->s('hdr'));
            $sh->height($r, 32);
            $g->row++;
            $lineRows = [];
            for ($l = 0; $l < $lines; $l++) {
                $r = $g->row;
                $sh->set($r, 1, $x->t('exp.line', $l + 1), $x->s('label_i'));
                $sh->set($r, 5, isset($policy[$l]) ? (float) $policy[$l][0] : null, $x->s('in_int'))->set($r, 6, isset($policy[$l]) ? (float) $policy[$l][1] : null, $x->s('in_pct'));
                $sh->set($r, 7, isset($policy[$l]) && $policy[$l][2] ? $yes : $no, $x->s('in_list'));
                $sh->list('G'.$r, [$yes, $no]);
                $sh->formula($r, 8, "IF(E$r=\"\",0,IF(E$r<30,0,ROUND(E$r/30,0)))", $x->s('flag'));
                $lineRows[] = $r;
                $g->row++;
            }
            $sc('res', $x->t('fa.res'), (float) ($a['reservation_rate'] ?? 0), 'in_pct', $x->t('fa.instNote'));
            $sc('contr', $x->t('fa.contr'), (float) ($a['contractual_rate'] ?? 0), 'in_pct', $x->t('fa.instNote'));
            $sc('after', $x->t('fa.after'), (float) ($a['after_months'] ?? 0), 'in_int', $x->t('fa.monthsNote'));
            $sc('igrace', $x->t('fa.igrace'), (float) ($a['installment_grace_period'] ?? 0), 'in_int', $x->t('fa.monthsNote'));
            $sc('icount', $x->t('fa.icount'), (float) ($a['installment_count'] ?? 1), 'in_int', $x->t('fa.monthsNote'));
            $list('iint', $x->t('fa.iint'), $ints[(string) (($a['payment_installment_interval'] ?? '') ?: 'monthly')] ?? $ints['monthly'], $ints);
            $sc('istep', $x->t('fa.istep'), 'IF('.$L('iint').'="'.$ints['monthly'].'",1,IF('.$L('iint').'="'.$ints['quarterly'].'",3,IF('.$L('iint').'="'.$ints['semi-annually'].'",6,12)))', 'lock_int', null, true);

            // funding
            $sc('eqPct', $x->t('fa.eqPct'), (float) ($a['equity_funding_rate'] ?? 100), 'in_pct', $x->t('fa.eqNote'));
            $sc('eqAmt', $x->t('fa.eqAmt'), $L('total').'*'.$L('eqPct').'/100', 'num', null, true);
            $sc('rate', $x->t('fa.rate'), (float) ($a['interest_rate'] ?? 0), 'in_pct', $x->t('fa.rateNote'));
            $sc('tenor', $x->t('fa.tenor'), (float) ($a['tenor'] ?? 0), 'in_int', $x->t('fa.tenorNote'));
            $loanInts = array_intersect_key($ints, array_flip(['monthly', 'quarterly', 'semi-annually']));
            $list('lint', $x->t('fa.lint'), $ints[(string) (($a['installment_interval'] ?? '') ?: 'monthly')] ?? $ints['monthly'], $loanInts);
            $sc('lstep', $x->t('fa.lstep'), 'IF('.$L('lint').'="'.$ints['monthly'].'",1,IF('.$L('lint').'="'.$ints['quarterly'].'",3,6))', 'lock_int', null, true);
            $sc('lgrace', $x->t('fa.lgrace'), (float) ($a['grace_period'] ?? 0), 'in_int', $x->t('fa.monthsNote'));

            // sharing of the manufacturing depreciation between the products
            $byRev = (bool) ($a['is_as_revenue_percentages'] ?? true);
            $r = $g->row;
            $sh->set($r, 1, $x->t('fa.sharing'), $x->s('label_b'))->set($r, 5, $x->t('exp.sharePct'), $x->s('hdr'))->set($r, 7, $x->t('mp.sellIdx'), $x->s('hdr'));
            $g->row++;
            $r = $g->row;
            $sh->set($r, 1, $x->t('mp.byRevenue'), $x->s('label_i'))->set($r, 5, $byRev ? $yes : $no, $x->s('in_list'));
            $sh->list('E'.$r, [$yes, $no]);
            $x->cells[$g->key.'.'.$p.'rev'] = $pre.'$E$'.$r;
            $g->row++;
            $alloc = (array) ($a['product_allocations'] ?? []);
            $pctFirst = $g->row;
            foreach ($m->products as $i => $pr) {
                $r = $g->row;
                $sh->set($r, 1, (string) ($pr['name'] ?? ''), $x->s('label_i'))->set($r, 5, (float) ($alloc[$pr['id']] ?? 0), $x->s('in_pct'));
                $sh->formula($r, 7, $x->name(SalesSheet::key($i).'.sellIdx'), $x->s('flag'));
                $g->row++;
            }
            $pctLast = $g->row - 1;
            $g->blank();

            // worked-out settings
            $g->section($x->t('fa.worked', $name));
            $g->later($p.'tot', $x->t('fa.tot'), fn () => 'SUM('.$g->rng($p.'drawR').')', 'num', $x->t('fa.totNote'));
            $sc('loanOn', $x->t('fa.loanOn'), 'IF(AND('.$L('eqPct').'<100,ABS('.$L('tot').')>=1),1,0)', 'lock_int', $x->t('fa.loanOnNote'), true);
            $g->later($p.'F0', $x->t('fa.F0'), fn () => 'IF('.$L('loanOn').'=1,COUNTIF('.$g->rng($p.'cd').',"<=0"),0)', 'lock_int', $x->t('fa.F0Note'));
            $g->later($p.'L0', $x->t('fa.L0'), fn () => 'IF('.$L('loanOn').'=1,SUMPRODUCT(MAX(('.$g->rng($p.'drawR').'<>0)*'.$idxR.')),0)', 'lock_int', $x->t('fa.L0Note'));
            $g->later($p.'amt', $x->t('fa.loanAmt'), fn () => 'IF('.$L('loanOn').'=1,INDEX('.$g->rng($p.'dend').',1,'.$L('L0').'+1),0)', 'num', $x->t('fa.loanAmtNote'));
            $sc('nsteps', $x->t('fa.nsteps'), 'INT('.$L('tenor').'/'.$L('lstep').')', 'lock_int', $x->t('fa.nstepsNote'), true);
            $sc('schedOn', $x->t('fa.schedOn'), 'IF(AND('.$L('loanOn').'=1,'.$L('amt').'>0,INT('.$L('tenor').')>=1,'.$L('nsteps').'<='.(self::PERIODS - 1).'),1,0)', 'lock_int', null, true);
            $sc('hasInst', $x->t('fa.hasInst'), 'IF(INT(('.$L('lgrace').'+'.$L('lstep').')/'.$L('lstep').')<='.$L('nsteps').',1,0)', 'lock_int', null, true);
            $sc('IS', $x->t('fa.IS'), 'IF('.$L('hasInst').'=1,'.$L('L0').'+INT(('.$L('lgrace').'+'.$L('lstep').')/'.$L('lstep').')*'.$L('lstep').',100000)', 'lock_int', $x->t('fa.ISNote'), true);
            $tblFirst = 0;
            $tblLast = 0;
            $g->later($p.'inst', $x->t('fa.inst'), function () use ($L, &$tblFirst, &$tblLast) {
                $J = '$J$'.$tblFirst.':$J$'.$tblLast;
                $K = '$K$'.$tblFirst.':$K$'.$tblLast;

                return 'IF(AND('.$L('schedOn').'=1,'.$L('hasInst').'=1),IF(INDEX('.$K.','.$L('nsteps').'+1)<>0,-INDEX('.$J.','.$L('nsteps').'+1)/INDEX('.$K.','.$L('nsteps').'+1),0),0)';
            }, 'num', $x->t('fa.instNote2'));
            $sc('opS', $x->t('fa.opS'), 'MAX(Z_OpIdx,'.$L('eIdx').')', 'lock_int', $x->t('fa.opSNote'), true);
            $sc('before', $x->t('fa.before'), 'IF(Z_OpIdx>='.$L('eIdx').','.$L('eIdx').',MAX(0,IF(Z_OpIdx=0,0,Z_OpIdx-1)))', 'lock_int', null, true);
            $sc('Fcap', $x->t('fa.Fcap'), 'MAX('.$L('before').','.$L('eIdx').')', 'lock_int', $x->t('fa.FcapNote'), true);
            $sc('T', $x->t('fa.T'), $L('Fcap').'+IF('.$L('eIdx').'='.$L('sIdx').',0,1)', 'lock_int', $x->t('fa.TNote'), true);
            $sc('Dm', $x->t('fa.Dm'), $L('depYears').'*12', 'lock_int', null, true);
            $g->blank();

            // the loan repayment table, one row per instalment period
            $g->section($x->t('fa.table', $name));
            $r = $g->row;
            foreach (['fa.t.i', 'fa.t.month', 'fa.t.date', 'fa.t.days', 'fa.t.factor', 'fa.t.lf', 'fa.t.inf', 'fa.t.beg', 'fa.t.int', 'fa.t.pay', 'fa.t.prin', 'fa.t.end'] as $c => $lk) {
                $sh->set($r, 5 + $c, $x->t($lk), $x->s('hdr'));
            }
            $sh->height($r, 32);
            $g->row++;
            $tblFirst = $g->row;
            $tblLast = $tblFirst + self::PERIODS - 1;
            for ($i = 0; $i < self::PERIODS; $i++) {
                $r = $tblFirst + $i;
                $act = 'AND('.$L('schedOn').'=1,E'.$r.'<='.$L('nsteps').')';
                $sh->set($r, 5, (float) $i, $x->s('flag'));
                $sh->formula($r, 6, "IF($act,".$L('L0').'+E'.$r.'*'.$L('lstep').',-1)', $x->s('flag'));
                $sh->formula($r, 7, "IF(F$r>=0,EDATE($firstDate,F$r),\"\")", $x->s('date'));
                $sh->formula($r, 8, $i === 0 ? '0' : "IF(F$r>=0,G$r-G".($r - 1).',0)', $x->s('flag'));
                $sh->formula($r, 9, $L('rate').'/100/360*H'.$r, $x->s('pctf'));
                $sh->formula($r, 10, $i === 0 ? "IF(F$r<0,0,".$L('amt').')' : "IF(F$r<0,0,IF(F$r<".$L('IS').','.$L('amt').',J'.($r - 1)."*(1+I$r)))", $x->s('num'));
                $sh->formula($r, 11, $i === 0 ? '0' : "IF(F$r<0,0,IF(F$r<{$L('IS')},0,IF(F$r={$L('IS')},-1,K".($r - 1)."*(1+I$r)-1)))", $x->s('num'));
                $sh->formula($r, 12, $i === 0 ? "IF(F$r<0,0,".$L('amt').')' : "IF(F$r<0,0,P".($r - 1).')', $x->s('num'));
                $sh->formula($r, 13, "L$r*I$r", $x->s('num'));
                $sh->formula($r, 14, "IF(F$r<0,0,IF(AND(F$r>=".$L('IS').','.$L('inst')."<>0),".$L('inst').",M$r))", $x->s('num'));
                $sh->formula($r, 15, "N$r-M$r", $x->s('num'));
                $sh->formula($r, 16, "IF(F$r<0,0,IF(ABS(L$r+M$r-N$r)<1,0,L$r+M$r-N$r))", $x->s('num'));
            }
            $g->row = $tblLast + 1;
            $F = '$F$'.$tblFirst.':$F$'.$tblLast;
            $col = fn (string $c) => '$'.$c.'$'.$tblFirst.':$'.$c.'$'.$tblLast;
            $g->blank();

            // monthly
            $g->section($x->t('fa.monthly', $name));
            $g->monthHeader();
            $keys = ['exec', 'payC', 'payI', 'pay', 'cp', 'eq', 'drawR', 'draw', 'cd', 'dwin', 'dbeg', 'dint', 'dend', 'dbs', 'sInt', 'sPay', 'sEnd', 'capi', 'upTot', 'upEnd', 'upTr', 'isr', 'grepl', 'gfin', 'ev', 'fr', 'to', 'dep', 'acc', 'nbv', 'dadm', 'dmfg'];
            foreach ($m->products as $i => $_) {
                $keys[] = 'a'.$i;
            }
            array_push($keys, 'pb', 'iis');
            $g->plan(array_map(fn ($k) => $p.$k, $keys));
            $idx = fn (K $k) => $k->cal('idx');
            $R = fn (string $key) => $g->rng($p.$key);

            $g->line($p.'exec', $x->t('fa.exec'), fn (K $k) => 'IF('.$L('dur').'=0,IF('.$idx($k).'='.$L('sIdx').','.$L('total').',0),IF(AND('.$idx($k).'>='.$L('sIdx').','.$idx($k).'<='.$L('eIdx').'),'.$L('total').'/'.$L('dur').',0))', ['total' => 'sum', 'bold' => true]);
            $g->line($p.'payC', $x->t('fa.payC'), function (K $k) use ($x, $L, $R, $lineRows, $yes, $idx) {
                $parts = [];
                foreach ($lineRows as $lr) {
                    $parts[] = 'IF($E$'.$lr.'="",0,$F$'.$lr.'/100*IF($G$'.$lr.'="'.$yes.'",IF('.$idx($k).'='.$L('sIdx').'+$H$'.$lr.','.$L('total').',0),'.$x->shift($R('exec'), '$H$'.$lr, $k->c).'))';
                }

                return implode('+', $parts);
            }, ['total' => 'sum', 'note' => $x->t('fa.payCNote')]);
            $g->line($p.'payI', $x->t('fa.payI'), function (K $k) use ($L, $idx) {
                $i = $idx($k);
                $off = "($i-({$L('sIdx')}+{$L('after')}+{$L('istep')}+{$L('igrace')}))";
                $bal = "((100-{$L('res')}-{$L('contr')})/100*{$L('total')})";
                $each = "($bal/MAX(1,INT({$L('icount')})))";

                return "IF($i={$L('sIdx')},{$L('res')}/100*{$L('total')}+IF({$L('after')}=0,{$L('contr')}/100*{$L('total')},0),0)"
                    ."+IF(AND({$L('after')}<>0,$i={$L('sIdx')}+{$L('after')}),{$L('contr')}/100*{$L('total')},0)"
                    ."+IF(AND($off>=0,MOD($off,{$L('istep')})=0,$off<MAX(1,INT({$L('icount')}))),MIN($each*{$L('istep')},$bal-$off/{$L('istep')}*$each*{$L('istep')}),0)";
            }, ['total' => 'sum', 'note' => $x->t('fa.payINote')]);
            $g->line($p.'pay', $x->t('fa.pay'), fn (K $k) => 'IF('.$L('terms').'="'.$terms['cash'].'",'.$k->r($p.'exec').',IF('.$L('terms').'="'.$terms['customize'].'",'.$k->r($p.'payC').','.$k->r($p.'payI').'))', ['total' => 'sum', 'bold' => true]);
            $g->line($p.'cp', $x->t('fa.cp'), fn (K $k) => $k->pr($p.'cp').'+'.$k->r($p.'pay'));
            $g->line($p.'eq', $x->t('fa.eq'), fn (K $k) => 'MIN('.$k->r($p.'pay').',MAX(0,'.$L('eqAmt').'-'.$k->pr($p.'cp').'))', ['total' => 'sum', 'bold' => true]);
            $g->line($p.'drawR', $x->t('fa.drawR'), fn (K $k) => 'MAX(0,'.$k->r($p.'cp').'-'.$L('eqAmt').')-MAX(0,'.$k->pr($p.'cp').'-'.$L('eqAmt').')', ['total' => 'sum', 'note' => $x->t('fa.drawRNote')]);
            $g->line($p.'draw', $x->t('fa.draw'), fn (K $k) => 'IF('.$L('loanOn').'=1,'.$k->r($p.'drawR').',0)', ['total' => 'sum', 'bold' => true]);
            $g->line($p.'cd', $x->t('fa.cd'), fn (K $k) => $k->pr($p.'cd').'+'.$k->r($p.'drawR'));
            $g->line($p.'dwin', $x->t('fa.dwin'), fn (K $k) => 'IF(AND('.$L('loanOn').'=1,'.$idx($k).'>='.$L('F0').','.$idx($k).'<='.$L('L0').'),1,0)', ['style' => 'flag']);
            $g->line($p.'dbeg', $x->t('fa.dbeg'), fn (K $k) => 'IF('.$k->r($p.'dwin').'=0,0,IF('.$idx($k).'='.$L('F0').','.$k->r($p.'draw').','.$k->pr($p.'dend').'+'.$k->r($p.'draw').'))');
            $g->line($p.'dint', $x->t('fa.dint'), fn (K $k) => 'IF(AND('.$k->r($p.'dwin').'=1,'.$idx($k).'>'.$L('F0').'),'.$k->r($p.'dbeg').'*'.$L('rate').'/100/360*('.$k->cal('date').'-'.$k->pcal('date').'),0)', ['total' => 'sum', 'note' => $x->t('fa.dintNote')]);
            $g->line($p.'dend', $x->t('fa.dend'), fn (K $k) => 'IF('.$k->r($p.'dwin').'=0,0,IF(ABS('.$k->r($p.'dbeg').'+'.$k->r($p.'dint').')<1,0,'.$k->r($p.'dbeg').'+'.$k->r($p.'dint').'))', ['bold' => true]);
            $g->line($p.'dbs', $x->t('fa.dbs'), fn (K $k) => 'IF(AND('.$k->r($p.'dwin').'=1,'.$idx($k).'<'.$L('L0').'),'.$k->r($p.'dend').',0)', ['note' => $x->t('fa.dbsNote')]);
            $g->line($p.'sInt', $x->t('fa.sInt'), fn (K $k) => 'SUMIF('.$F.','.$idx($k).','.$col('M').')', ['total' => 'sum']);
            $g->line($p.'sPay', $x->t('fa.sPay'), fn (K $k) => 'SUMIF('.$F.','.$idx($k).','.$col('N').')', ['total' => 'sum', 'bold' => true]);
            $g->line($p.'sEnd', $x->t('fa.sEnd'), fn (K $k) => 'IF(COUNTIF('.$F.','.$idx($k).')>0,SUMIF('.$F.','.$idx($k).','.$col('P').'),'.$k->pr($p.'sEnd').')', ['bold' => true, 'note' => $x->t('fa.sEndNote')]);
            $g->line($p.'capi', $x->t('fa.capi'), fn (K $k) => 'IF('.$idx($k).'<='.$L('Fcap').','.$k->r($p.'dint').'+'.$k->r($p.'sInt').',0)', ['total' => 'sum']);
            $g->line($p.'upTot', $x->t('fa.upTot'), fn (K $k) => 'IF('.$idx($k).'<='.$L('T').','.$k->pr($p.'upTot').'+'.$k->r($p.'exec').'+'.$k->r($p.'capi').',0)');
            $g->line($p.'upEnd', $x->t('fa.upEnd'), fn (K $k) => 'IF('.$idx($k).'<'.$L('T').','.$k->r($p.'upTot').',0)', ['bold' => true]);
            $g->line($p.'upTr', $x->t('fa.upTr'), fn (K $k) => 'IF('.$idx($k).'='.$L('T').','.$k->r($p.'upTot').',0)', ['total' => 'sum']);
            $g->line($p.'isr', $x->t('fa.isr'), fn (K $k) => 'IF('.$L('replYears').'*12=0,IF('.$idx($k).'='.$L('opS').',1,0),IF(AND('.$idx($k).'>'.$L('opS').',MOD('.$idx($k).'-'.$L('opS').','.$L('replYears').'*12)=0,'.$idx($k).'-'.$L('replYears').'*12<=Z_StudyEnd),1,0))', ['style' => 'flag', 'note' => $x->t('fa.isrNote')]);
            $g->line($p.'grepl', $x->t('fa.grepl'), fn (K $k) => 'IF('.$k->r($p.'isr').'=1,('.$k->pr($p.'gfin').'+'.$k->r($p.'upTr').')*'.$L('replRate').'/100,0)', ['total' => 'sum']);
            $g->line($p.'gfin', $x->t('fa.gfin'), fn (K $k) => $k->pr($p.'gfin').'+'.$k->r($p.'upTr').'+'.$k->r($p.'grepl'), ['bold' => true]);
            $g->line($p.'ev', $x->t('fa.ev'), fn (K $k) => 'IF('.$L('Dm').'>0,('.$k->r($p.'upTr').'+'.$k->r($p.'grepl').')/'.$L('Dm').',0)', ['note' => $x->t('fa.evNote')]);
            $g->line($p.'fr', $x->t('fa.fr'), fn (K $k) => 'IF('.$L('T').'<'.$L('opS').','.$L('opS').','.$idx($k).'+1)', ['style' => 'flag']);
            $g->line($p.'to', $x->t('fa.to'), fn (K $k) => 'IF(AND('.$k->r($p.'isr').'=1,'.$L('T').'<='.$L('opS').'),'.$idx($k).'+'.$L('Dm').','.$k->r($p.'fr').'+'.$L('Dm').'-1)', ['style' => 'flag']);
            $g->line($p.'dep', $x->t('fa.dep'), fn (K $k) => 'SUMPRODUCT('.$R('ev').'*('.$idxR.'<'.$idx($k).')*('.$R('fr').'<='.$idx($k).')*('.$R('to').'>='.$idx($k).'))', ['total' => 'sum', 'bold' => true]);
            $g->line($p.'acc', $x->t('fa.acc'), fn (K $k) => $k->pr($p.'acc').'+'.$k->r($p.'dep'));
            $g->line($p.'nbv', $x->t('fa.nbv'), fn (K $k) => $k->r($p.'gfin').'-'.$k->r($p.'acc'), ['bold' => true]);
            $g->line($p.'dadm', $x->t('fa.dadm'), fn (K $k) => $L('admin').'/100*'.$k->r($p.'dep'), ['total' => 'sum']);
            $g->line($p.'dmfg', $x->t('fa.dmfg'), fn (K $k) => $L('mfg').'/100*'.$k->r($p.'dep'), ['total' => 'sum']);
            foreach ($m->products as $i => $pr) {
                $g->line($p.'a'.$i, $x->t('fa.toProduct', (string) ($pr['name'] ?? '')), function (K $k) use ($x, $g, $p, $i, $pctFirst, $pctLast, $yes, $L) {
                    $range = fn (string $c) => '$'.$c.'$'.$pctFirst.':$'.$c.'$'.$pctLast;
                    $row = $pctFirst + $i;
                    $typed = ShareSheet::typed($k, '$E$'.$row, '$G$'.$row, $range('E'), $range('G'));

                    return '(LEN('.$L('name').')>0)*IF('.$L('rev').'="'.$yes.'",'.$x->x('share.rs'.$i, $k->c).",$typed)*".$k->r($p.'dmfg');
                }, ['total' => 'sum']);
            }
            $g->line($p.'pb', $x->t('fa.pb'), fn (K $k) => 'IF(ABS('.$k->pr($p.'pb').'+'.$k->r($p.'exec').'-'.$k->r($p.'pay').')<0.000001,0,'.$k->pr($p.'pb').'+'.$k->r($p.'exec').'-'.$k->r($p.'pay').')', ['bold' => true]);
            $g->line($p.'iis', $x->t('fa.iis'), fn (K $k) => 'IF('.$idx($k).'>='.$L('T').','.$k->r($p.'dint').',0)', ['total' => 'sum', 'note' => $x->t('fa.iisNote')]);
            $g->flush();
            $g->blank(2);
        }
        $sh->freeze(4, Ctx::FIRST);
    }
}
