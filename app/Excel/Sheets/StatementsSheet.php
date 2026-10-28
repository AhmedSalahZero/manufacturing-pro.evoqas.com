<?php

namespace App\Excel\Sheets;

use App\Excel\Ctx;
use App\Excel\Grid;
use App\Excel\K;
use App\Excel\Sheet;

// The financial statements: Income Statement, Taxes (VAT and corporate tax), Cash Flow and Balance Sheet (with the yearly change in working
// capital and the funding mix). Only adds and subtracts the monthly rows of the earlier sheets, exactly as FinancialStatementsCalculator does.
// Every row is shown for the months of the study only.
final class StatementsSheet
{
    private static function has(Ctx $x, string $key): bool
    {
        return isset($x->rows[$key]);
    }

    /** "a+b+c" of the rows (same column) that exist; '0' if none. @param list<string> $keys */
    private static function sum(Ctx $x, K $k, array $keys): string
    {
        $parts = [];
        foreach ($keys as $key) {
            if (self::has($x, $key)) {
                $parts[] = $k->x($key);
            }
        }

        return $parts === [] ? '0' : implode('+', $parts);
    }

    /** @return list<string> */
    private static function perAsset(Ctx $x, string $suffix): array
    {
        $out = [];
        foreach ($x->m->assets as $j => $_) {
            $out[] = 'fa.f'.$j.'_'.$suffix;
        }

        return $out;
    }

    /** @return list<string> */
    private static function perRm(Ctx $x, string $suffix): array
    {
        $out = [];
        foreach ($x->m->rms as $j => $_) {
            $out[] = 'rm.r'.$j.'_'.$suffix;
        }

        return $out;
    }

    /** @return list<string> */
    private static function perProduct(Ctx $x, string $suffix): array
    {
        $out = [];
        foreach ($x->m->products as $i => $_) {
            $out[] = SalesSheet::key($i).'.'.$suffix;
        }

        return $out;
    }

    private static function ob(Ctx $x, string $cell): string
    {
        return $x->m->new ? '0' : $x->name('ob.'.$cell);
    }

    private static function obRow(Ctx $x, K $k, string $key): string
    {
        return $x->m->new ? '0' : $k->x('ob.'.$key);
    }

    public static function build(Ctx $x): void
    {
        self::income($x);
        self::taxes($x);
        self::cash($x);
        self::balance($x);
    }

    // ── Income statement ──────────────────────────────────────────

    private static function income(Ctx $x): void
    {
        $m = $x->m;
        $sh = $x->sheet('is', $x->t('n.is'));
        $g = new Grid($x, $sh, 'is');
        $g->title($x->t('is.title'), $x->t('is.sub'));
        $g->monthHeader();
        $SF = fn (K $k) => $k->cal('study');
        $keys = [];
        foreach ($m->products as $i => $_) {
            $keys[] = 'rev'.$i;
        }
        array_push($keys, 'rev', '_', 'cgRm', 'cgDl', 'cgOh', 'cogs', 'gp', '_', 'xGen', 'xSales', 'xMkt');
        if ($m->new) {
            array_push($keys, 'xStart', 'xPre');
        }
        array_push($keys, 'sga', '_', 'depn', 'ebitda', 'ebit', '_');
        foreach ($m->assets as $j => $_) {
            $keys[] = 'fin'.$j;
        }
        array_push($keys, 'finO', 'fin', 'ebt', 'tax', 'net', '_', 'ret');
        $g->plan($keys);

        foreach ($m->products as $i => $pr) {
            $g->line('rev'.$i, (string) ($pr['name'] ?? ''), fn (K $k) => $SF($k).'*'.$k->x(SalesSheet::key($i).'.v'), ['total' => 'sum']);
        }
        $revSum = fn (K $k) => $m->products === [] ? '0' : implode('+', array_map(fn ($i) => $k->r('rev'.$i), array_keys($m->products)));
        $g->line('rev', $x->t('is.rev'), $revSum, ['total' => 'sum', 'bold' => true]);
        $g->blank();
        $g->line('cgRm', $x->t('is.cgRm'), fn (K $k) => $SF($k).'*'.self::sum($x, $k, ['cost.t_rm']), ['total' => 'sum']);
        $g->line('cgDl', $x->t('is.cgDl'), fn (K $k) => $SF($k).'*'.self::sum($x, $k, ['cost.t_dl']), ['total' => 'sum']);
        $g->line('cgOh', $x->t('is.cgOh'), fn (K $k) => $SF($k).'*'.self::sum($x, $k, ['cost.t_oh']), ['total' => 'sum']);
        $g->line('cogs', $x->t('is.cogs'), fn (K $k) => $k->r('cgRm').'+'.$k->r('cgDl').'+'.$k->r('cgOh'), ['total' => 'sum', 'bold' => true]);
        $g->line('gp', $x->t('is.gp'), fn (K $k) => $k->r('rev').'-'.$k->r('cogs'), ['total' => 'sum', 'bold' => true]);
        $g->blank();
        $adm = fn (K $k) => self::sum($x, $k, self::perAsset($x, 'dadm')).'+'.self::obRow($x, $k, 't_adm');
        $g->line('xGen', $x->t('is.xGen'), fn (K $k) => $SF($k).'*('.self::sum($x, $k, ['exp.cat_general-expenses', 'mp.cgen']).'+'.$adm($k).')', ['total' => 'sum']);
        $g->line('xSales', $x->t('is.xSales'), fn (K $k) => $SF($k).'*('.self::sum($x, $k, ['exp.cat_sales', 'mp.csales']).')', ['total' => 'sum']);
        $g->line('xMkt', $x->t('is.xMkt'), fn (K $k) => $SF($k).'*('.self::sum($x, $k, ['exp.cat_marketing', 'mp.cmkt']).')', ['total' => 'sum']);
        if ($m->new) {
            $g->line('xStart', $x->t('is.xStart'), fn (K $k) => $SF($k).'*('.self::sum($x, $k, ['exp.cat_start-up-expenses']).')', ['total' => 'sum']);
            $g->line('xPre', $x->t('is.xPre'), fn (K $k) => $SF($k).'*('.self::sum($x, $k, ['exp.cat_pre-operating-expenses']).')', ['total' => 'sum']);
        }
        $g->line('sga', $x->t('is.sga'), fn (K $k) => $k->r('xGen').'+'.$k->r('xSales').'+'.$k->r('xMkt').($m->new ? '+'.$k->r('xStart').'+'.$k->r('xPre') : ''), ['total' => 'sum', 'bold' => true]);
        $g->blank();
        $g->line('depn', $x->t('is.depn'), fn (K $k) => $SF($k).'*('.self::sum($x, $k, ['cost.t_dp']).'+'.$adm($k).')', ['total' => 'sum', 'note' => $x->t('is.depnNote')]);
        $g->line('ebitda', $x->t('is.ebitda'), fn (K $k) => $k->r('gp').'+'.$k->r('depn').'-'.$k->r('sga'), ['total' => 'sum', 'bold' => true]);
        $g->line('ebit', $x->t('is.ebit'), fn (K $k) => $k->r('gp').'-'.$k->r('sga'), ['total' => 'sum', 'bold' => true]);
        $g->blank();
        foreach ($m->assets as $j => $a) {
            $f = 'f'.$j.'_';
            $g->line('fin'.$j, $x->t('is.finAsset', (string) ($a['name'] ?? '')), fn (K $k) => $SF($k).'*IF('.$x->name('fa.'.$f.'schedOn').'=1,'.$k->x('fa.'.$f.'iis').'+IF('.$k->cal('idx').'>='.$x->name('fa.'.$f.'T').','.$k->x('fa.'.$f.'sInt').',0),0)', ['total' => 'sum']);
        }
        $g->line('finO', $x->t('is.finO'), fn (K $k) => $SF($k).'*('.self::obRow($x, $k, 't_loanInt').')', ['total' => 'sum']);
        $g->line('fin', $x->t('is.fin'), function (K $k) use ($m) {
            $parts = [$k->r('finO')];
            foreach ($m->assets as $j => $_) {
                $parts[] = $k->r('fin'.$j);
            }

            return implode('+', $parts);
        }, ['total' => 'sum', 'bold' => true]);
        $g->line('ebt', $x->t('is.ebt'), fn (K $k) => $k->r('ebit').'-'.$k->r('fin'), ['total' => 'sum', 'bold' => true]);
        $ebt = $g->rng('ebt');
        $g->line('tax', $x->t('is.tax'), fn (K $k) => $k->cal('yend').'*MAX(0,SUMIFS('.$ebt.','.$x->calRng('year').','.$k->cal('year').','.$x->calRng('study').',1))*Z_Tax/100', ['total' => 'sum', 'note' => $x->t('is.taxNote')]);
        $g->line('net', $x->t('is.net'), fn (K $k) => $k->r('ebt').'-'.$k->r('tax'), ['total' => 'sum', 'bold' => true]);
        $g->blank();
        $g->line('ret', $x->t('is.ret'), fn (K $k) => $SF($k).'*IF('.$k->cal('idx').'=0,'.self::ob($x, 'retained').','.$k->pr('ret').'+'.$k->pr('net').')', ['note' => $x->t('is.retNote')]);
        $sh->freeze(4, Ctx::FIRST);
    }

    // ── VAT and corporate tax ─────────────────────────────────────

    private static function taxes(Ctx $x): void
    {
        $m = $x->m;
        $sh = $x->sheet('tax', $x->t('n.tax'));
        $g = new Grid($x, $sh, 'tax');
        $g->title($x->t('tax.title'), $x->t('tax.sub'));
        $SF = fn (K $k) => $k->cal('study');
        $g->section($x->t('tax.figures'));
        $g->scalar('fa', $x->t('tax.firstApril'), 'IF(MONTH(Z_Start)<=4,4-MONTH(Z_Start),-1)', 'lock_int', $x->t('tax.firstAprilNote'), true);
        $g->scalar('openVat', $x->t('ob.vat'), self::ob($x, 'vatAmt'), 'num', null, true);
        $g->scalar('openCt', $x->t('ob.ct'), self::ob($x, 'ctAmt'), 'num', null, true);
        $g->blank();
        $g->section($x->t('tax.vat'));
        $g->monthHeader();
        $g->plan(['vsal', 'vpur', 'vadd', 'vbeg', 'vdue', 'vpay', 'vend']);
        $g->line('vsal', $x->t('tax.vsal'), fn (K $k) => self::sum($x, $k, self::perProduct($x, 'c_vat')), ['total' => 'sum']);
        $g->line('vpur', $x->t('tax.vpur'), fn (K $k) => self::sum($x, $k, self::perRm($x, 'vat')), ['total' => 'sum']);
        $g->line('vadd', $x->t('tax.vadd'), fn (K $k) => $k->r('vsal').'-'.$k->r('vpur'), ['total' => 'sum', 'bold' => true]);
        $g->line('vbeg', $x->t('tax.beg'), fn (K $k) => $k->pr('vend'));
        $g->line('vdue', $x->t('tax.due'), fn (K $k) => $k->r('vbeg').'+'.$k->r('vadd'));
        $g->line('vpay', $x->t('tax.vpay'), fn (K $k) => 'IF('.$k->pr('vdue').'<=0,0,MAX(0,'.$k->pr('vend').'))', ['total' => 'sum', 'bold' => true, 'note' => $x->t('tax.vpayNote')]);
        $g->line('vend', $x->t('tax.vend'), fn (K $k) => $k->r('vdue').'-'.$k->r('vpay'), ['bold' => true, 'before' => $g->lcell('openVat'), 'note' => $x->t('tax.vendNote')]);
        $g->blank();
        // the corporate tax statement must be placed by hand: its section header sits in the planned gap
        $g->section($x->t('tax.corp'));
        $g->monthHeader();
        $g->plan(['cwh', 'ctax', 'cbeg', 'cdue', 'cyd', 'cpay', 'cend']);
        $g->line('cwh', $x->t('tax.cwh'), fn (K $k) => $SF($k).'*('.self::sum($x, $k, self::perProduct($x, 'c_wh')).')', ['total' => 'sum', 'note' => $x->t('tax.cwhNote')]);
        $g->line('ctax', $x->t('tax.ctax'), fn (K $k) => $k->x('is.tax'), ['total' => 'sum']);
        $g->line('cbeg', $x->t('tax.beg'), fn (K $k) => $k->pr('cend'));
        $g->line('cdue', $x->t('tax.due'), fn (K $k) => $k->r('cbeg').'-'.$k->r('cwh').'+'.$k->r('ctax'));
        $g->line('cyd', $x->t('tax.cyd'), fn (K $k) => 'IF('.$k->cal('yend').'=1,MAX(0,'.$k->r('cdue').'),'.$k->pr('cyd').')', ['note' => $x->t('tax.cydNote')]);
        $g->line('cpay', $x->t('tax.cpay'), fn (K $k) => $SF($k).'*(IF(AND('.$k->cal('idx').'='.$g->lcell('fa').','.$g->lcell('openCt').'<>0),'.$g->lcell('openCt').',0)+IF('.$k->cal('mnum').'=4,'.$k->pr('cyd').',0))', ['total' => 'sum', 'bold' => true, 'note' => $x->t('tax.cpayNote')]);
        $g->line('cend', $x->t('tax.cend'), fn (K $k) => $k->r('cdue').'-'.$k->r('cpay'), ['bold' => true, 'before' => $g->lcell('openCt')]);
        $sh->freeze(4, Ctx::FIRST);
    }

    // ── Cash flow ─────────────────────────────────────────────────

    private static function cash(Ctx $x): void
    {
        $m = $x->m;
        $sh = $x->sheet('cf', $x->t('n.cf'));
        $g = new Grid($x, $sh, 'cf');
        $g->title($x->t('cf.title'), $x->t('cf.sub'));
        $g->monthHeader();
        $SF = fn (K $k) => $k->cal('study');
        $g->plan(['inColl', 'inRcv', 'inDeb', 'inEq', 'inLoan', 'in', '_', 'outRm', 'outSup', 'outCre', 'outSal', 'outSalTax', 'outCwh', 'outVat', 'outCt', 'outExp', 'outFa', 'outRepl', 'outLoan', 'outOLoan', 'outOlt', 'out', '_', 'beg', 'net', 'inj', 'end']);
        $row = fn (string $key, string $label, callable $f, array $o = ['total' => 'sum']) => $g->line($key, $label, fn (K $k) => $SF($k).'*('.$f($k).')', $o);
        $row('inColl', $x->t('cf.inColl'), fn (K $k) => self::sum($x, $k, self::perProduct($x, 'c_pay')));
        $row('inRcv', $x->t('cf.inRcv'), fn (K $k) => self::obRow($x, $k, 't_rcvSet'));
        $row('inDeb', $x->t('cf.inDeb'), fn (K $k) => self::obRow($x, $k, 't_debSet'));
        $row('inEq', $x->t('cf.inEq'), fn (K $k) => self::sum($x, $k, self::perAsset($x, 'eq')));
        $row('inLoan', $x->t('cf.inLoan'), fn (K $k) => self::sum($x, $k, self::perAsset($x, 'draw')));
        $g->line('in', $x->t('cf.in'), fn (K $k) => implode('+', array_map(fn ($c) => $k->r($c), ['inColl', 'inRcv', 'inDeb', 'inEq', 'inLoan'])), ['total' => 'sum', 'bold' => true]);
        $g->blank();
        $row('outRm', $x->t('cf.outRm'), fn (K $k) => self::sum($x, $k, self::perRm($x, 'net')));
        $row('outSup', $x->t('cf.outSup'), fn (K $k) => self::obRow($x, $k, 't_supSet'));
        $row('outCre', $x->t('cf.outCre'), fn (K $k) => self::obRow($x, $k, 't_creSet'));
        $row('outSal', $x->t('cf.outSal'), fn (K $k) => self::sum($x, $k, ['mp.payT']));
        $row('outSalTax', $x->t('cf.outSalTax'), fn (K $k) => self::sum($x, $k, ['mp.lpayT']));
        $row('outCwh', $x->t('cf.outCwh'), fn (K $k) => self::sum($x, $k, self::perRm($x, 'wpay')).($x->m->new ? '' : '+IF('.$k->cal('idx').'=0,'.$x->name('ob.whAmt').',0)'));
        $row('outVat', $x->t('cf.outVat'), fn (K $k) => $k->x('tax.vpay'));
        $row('outCt', $x->t('cf.outCt'), fn (K $k) => $k->x('tax.cpay'));
        $row('outExp', $x->t('cf.outExp'), fn (K $k) => self::sum($x, $k, ['exp.payT']));
        $row('outFa', $x->t('cf.outFa'), fn (K $k) => self::sum($x, $k, self::perAsset($x, 'pay')));
        $row('outRepl', $x->t('cf.outRepl'), fn (K $k) => self::sum($x, $k, self::perAsset($x, 'grepl')));
        $row('outLoan', $x->t('cf.outLoan'), fn (K $k) => self::sum($x, $k, self::perAsset($x, 'sPay')));
        $row('outOLoan', $x->t('cf.outOLoan'), fn (K $k) => self::obRow($x, $k, 't_loanInst').'+'.self::obRow($x, $k, 't_loanInt'));
        $row('outOlt', $x->t('cf.outOlt'), fn (K $k) => self::obRow($x, $k, 't_ltSet'));
        $g->line('out', $x->t('cf.out'), fn (K $k) => implode('+', array_map(fn ($c) => $k->r($c), ['outRm', 'outSup', 'outCre', 'outSal', 'outSalTax', 'outCwh', 'outVat', 'outCt', 'outExp', 'outFa', 'outRepl', 'outLoan', 'outOLoan', 'outOlt'])), ['total' => 'sum', 'bold' => true]);
        $g->blank();
        $g->line('beg', $x->t('cf.beg'), fn (K $k) => $SF($k).'*'.$k->pr('end'));
        $g->line('net', $x->t('cf.net'), fn (K $k) => $SF($k).'*('.$k->r('beg').'+'.$k->r('in').'-'.$k->r('out').')');
        $g->line('inj', $x->t('cf.inj'), fn (K $k) => 'IF('.$k->r('net').'<0,-'.$k->r('net').',0)', ['total' => 'sum', 'note' => $x->t('cf.injNote')]);
        $g->line('end', $x->t('cf.end'), fn (K $k) => $k->r('net').'+'.$k->r('inj'), ['bold' => true, 'before' => self::ob($x, 'cashAmt')]);
        $sh->freeze(4, Ctx::FIRST);
    }

    // ── Balance sheet ─────────────────────────────────────────────

    private static function balance(Ctx $x): void
    {
        $m = $x->m;
        $sh = $x->sheet('bs', $x->t('n.bs'));
        $g = new Grid($x, $sh, 'bs');
        $g->title($x->t('bs.title'), $x->t('bs.sub'));
        $g->monthHeader();
        $SF = fn (K $k) => $k->cal('study');
        $g->plan(['S', 'fa', 'pup', 'cash', 'rec', 'fg', 'rm', 'deb', 'vatR', 'tca', 'ta', '_', 'S', 'sp', 'fac', 'vatP', 'cwh', 'stax', 'ctax', 'ocr', 'tcl', 'loans', 'olt', 'tltl', '_', 'S', 'paid', 'addl', 'legal', 'ret', 'prof', 'teq', '_', 'chk']);
        $row = fn (string $key, string $label, callable $f, array $o = []) => $g->line($key, $label, fn (K $k) => $SF($k).'*('.$f($k).')', $o + ['style' => 'num']);
        $g->section($x->t('bs.assets'));
        $row('fa', $x->t('bs.fa'), fn (K $k) => self::obRow($x, $k, 't_nbv').'+'.self::sum($x, $k, self::perAsset($x, 'nbv')));
        $row('pup', $x->t('bs.pup'), fn (K $k) => self::sum($x, $k, self::perAsset($x, 'upEnd')));
        $row('cash', $x->t('bs.cash'), fn (K $k) => $k->x('cf.end'));
        $row('rec', $x->t('bs.rec'), fn (K $k) => self::obRow($x, $k, 't_rcvEnd').'+'.self::sum($x, $k, self::perProduct($x, 'c_end')));
        $row('fg', $x->t('bs.fg'), fn (K $k) => self::sum($x, $k, ['cost.t_fg']));
        $row('rm', $x->t('bs.rm'), fn (K $k) => self::sum($x, $k, self::perRm($x, 'iendR')));
        $row('deb', $x->t('bs.deb'), fn (K $k) => self::obRow($x, $k, 't_debEnd').'+'.self::sum($x, $k, ['exp.preT']));
        $row('vatR', $x->t('bs.vatR'), fn (K $k) => 'MAX(-'.$k->x('tax.vend').',0)', ['note' => $x->t('bs.vatRNote')]);
        $g->line('tca', $x->t('bs.tca'), fn (K $k) => implode('+', array_map(fn ($c) => $k->r($c), ['cash', 'rec', 'rm', 'fg', 'deb', 'vatR'])), ['bold' => true]);
        $g->line('ta', $x->t('bs.ta'), fn (K $k) => $k->r('tca').'+'.$k->r('pup').'+'.$k->r('fa'), ['bold' => true]);
        $g->blank();
        $g->section($x->t('bs.liab'));
        $row('sp', $x->t('bs.sp'), fn (K $k) => self::obRow($x, $k, 't_supEnd').'+'.self::sum($x, $k, self::perRm($x, 'end')));
        $row('fac', $x->t('bs.fac'), fn (K $k) => self::sum($x, $k, self::perAsset($x, 'pb')));
        $row('vatP', $x->t('bs.vatP'), fn (K $k) => 'MAX('.$k->x('tax.vend').',0)');
        $row('cwh', $x->t('bs.cwh'), fn (K $k) => self::sum($x, $k, self::perRm($x, 'wend')));
        $row('stax', $x->t('bs.stax'), fn (K $k) => self::sum($x, $k, ['mp.lendT']));
        $row('ctax', $x->t('bs.ctax'), fn (K $k) => $k->x('tax.cend'));
        $row('ocr', $x->t('bs.ocr'), fn (K $k) => self::sum($x, $k, ['exp.endT']).'+'.self::obRow($x, $k, 't_creEnd'));
        $g->line('tcl', $x->t('bs.tcl'), fn (K $k) => implode('+', array_map(fn ($c) => $k->r($c), ['sp', 'fac', 'vatP', 'cwh', 'stax', 'ctax', 'ocr'])), ['bold' => true]);
        $row('loans', $x->t('bs.loans'), fn (K $k) => self::obRow($x, $k, 't_loanEnd').'+'.self::sum($x, $k, self::perAsset($x, 'sEnd')).'+'.self::sum($x, $k, self::perAsset($x, 'dbs')));
        $row('olt', $x->t('bs.olt'), fn (K $k) => self::obRow($x, $k, 't_ltEnd'));
        $g->line('tltl', $x->t('bs.tltl'), fn (K $k) => $k->r('loans').'+'.$k->r('olt'), ['bold' => true]);
        $g->blank();
        $g->section($x->t('bs.equity'));
        $row('paid', $x->t('bs.paid'), fn (K $k) => self::ob($x, 'paid'));
        $g->line('addl', $x->t('bs.addl'), fn (K $k) => $SF($k).'*('.$k->pr('addl').'+'.$k->x('cf.inj').'+'.$k->x('cf.inEq').')', ['note' => $x->t('bs.addlNote')]);
        $row('legal', $x->t('bs.legal'), fn (K $k) => self::ob($x, 'legal'));
        $row('ret', $x->t('bs.ret'), fn (K $k) => $k->x('is.ret'));
        $row('prof', $x->t('bs.prof'), fn (K $k) => $k->x('is.net'));
        $g->line('teq', $x->t('bs.teq'), fn (K $k) => implode('+', array_map(fn ($c) => $k->r($c), ['paid', 'addl', 'legal', 'ret', 'prof'])), ['bold' => true]);
        $g->blank();
        $g->line('chk', $x->t('bs.chk'), fn (K $k) => $k->r('ta').'-'.$k->r('tcl').'-'.$k->r('tltl').'-'.$k->r('teq'), ['bold' => true, 'note' => $x->t('bs.chkNote')]);
        $sh->conditional('E'.$g->rowOf('chk').':'.$x->lastCol().$g->rowOf('chk'), 'ABS(E'.$g->rowOf('chk').')>=0.01', 0);
        $g->blank(2);

        // ── yearly: change in working capital and funding mix ──
        $g->section($x->t('bs.yearly'));
        $g->yearHeader('yh');
        $yend = $x->calRng('yend');
        $yidx = $x->calRng('yidx');
        $atYear = fn (string $key, int $y) => 'SUMPRODUCT(('.$yend.'=1)*('.$yidx.'='.$y.')*'.$g->rng($key).')';
        $has = fn (int $y) => 'SUMPRODUCT(('.$yend.'=1)*('.$yidx.'='.$y.'))>0';
        // opening figures
        $fgOpen = [];
        foreach ($m->products as $i => $_) {
            $kk = SalesSheet::key($i);
            $fgOpen[] = 'Z_NewCo=0';
        }
        $fgv = [];
        foreach ($m->products as $i => $_) {
            $kk = SalesSheet::key($i);
            $fgv[] = $x->name($kk.'.fgv_rm').'+'.$x->name($kk.'.fgv_dl').'+'.$x->name($kk.'.fgv_oh');
        }
        $rmv = [];
        foreach ($m->rms as $j => $_) {
            $rmv[] = $x->name('rm.r'.$j.'_beg');
        }
        $newZero = fn (array $parts) => $parts === [] || $m->new ? '0' : '('.implode('+', $parts).')';
        $obItems = function (string $pf, int $n) use ($x) {
            $parts = [];
            for ($i = 0; $i < $n; $i++) {
                $parts[] = $x->name('ob.'.$pf.$i.'_amt');
            }

            return $parts === [] || $x->m->new ? '0' : '('.implode('+', $parts).')';
        };
        $op = $m->opening;
        $items = [
            ['rec', true, 'bs.rec', $m->new ? '0' : $x->name('ob.cs0_amt')],
            ['fg', true, 'bs.fg', $newZero($fgv)],
            ['rm', true, 'bs.rm', $newZero($rmv)],
            ['deb', true, 'bs.deb', $obItems('d', count((array) ($op['other_debtors'] ?? [])))],
            ['sp', false, 'bs.sp', $m->new ? '0' : $x->name('ob.su0_amt')],
            ['ocr', false, 'bs.ocr', $obItems('c', count((array) ($op['other_creditors'] ?? [])))],
            ['olt', false, 'bs.olt', $obItems('lt', count((array) ($op['other_long_term'] ?? [])))],
        ];
        $yearly = [];
        foreach ($items as [$key, $isAsset, $label, $open]) {
            $yearly[] = 'wc_'.$key;
        }
        $g->plan(array_merge($yearly, ['wcNet', '_', 'eqY', 'loanY', 'capY', 'fEq', 'fDebt']));
        foreach ($items as [$key, $isAsset, $label, $open]) {
            $g->yearCalc('wc_'.$key, $x->t('bs.wc', $x->t($label)), function (int $y, string $c, string $pc) use ($key, $isAsset, $open, $atYear, $has, $g) {
                $cur = $atYear($key, $y);
                $prev = $y === 0 ? $open : $atYear($key, $y - 1);

                return 'IF('.$has($y).','.($isAsset ? "$prev-$cur" : "$cur-$prev").',0)';
            }, 'num', $isAsset ? $x->t('bs.wcAsset') : $x->t('bs.wcLiab'));
        }
        $g->yearCalc('wcNet', $x->t('bs.wcNet'), function (int $y, string $c) use ($yearly, $g) {
            return implode('+', array_map(fn ($k) => $c.$g->rowOf($k), $yearly));
        }, 'num', $x->t('bs.wcNetNote'));
        $g->blank();
        $g->yearCalc('eqY', $x->t('bs.eqY'), fn (int $y) => 'IF('.$has($y).','.$atYear('teq', $y).',0)', 'num');
        $g->yearCalc('loanY', $x->t('bs.loanY'), fn (int $y) => 'IF('.$has($y).','.$atYear('loans', $y).',0)', 'num');
        $g->yearCalc('capY', $x->t('bs.capY'), fn (int $y, string $c) => $c.$g->rowOf('eqY').'+'.$c.$g->rowOf('loanY'), 'num');
        $g->yearCalc('fEq', $x->t('bs.fEq'), fn (int $y, string $c) => 'IF('.$c.$g->rowOf('capY').'=0,0,'.$c.$g->rowOf('eqY').'/'.$c.$g->rowOf('capY').'*100)', 'pct');
        $g->yearCalc('fDebt', $x->t('bs.fDebt'), fn (int $y, string $c) => 'IF('.$c.$g->rowOf('capY').'=0,0,'.$c.$g->rowOf('loanY').'/'.$c.$g->rowOf('capY').'*100)', 'pct');
        $sh->freeze(4, Ctx::FIRST);
    }
}
