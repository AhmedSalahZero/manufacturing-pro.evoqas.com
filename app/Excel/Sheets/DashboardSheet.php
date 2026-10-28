<?php

namespace App\Excel\Sheets;

use App\Excel\Ctx;
use App\Excel\Grid;
use App\Excel\Sheet;

// The Dashboard page: revenue and profit by year, costs as a % of revenue, cash in and out, the discounted cash flow (free cash flow, WACC,
// terminal value, NPV, IRR), the payback, the liquidity and activity ratios and the investment. Same rules as DashboardCalculator.
// The tables are always yearly (the application shows a one-year study month by month; the valuation is yearly there too).
final class DashboardSheet
{
    /** Rows of the payback table: months of the study and the years after it (30 years). */
    public const PAYBACK_ROWS = 360;

    public static function build(Ctx $x): void
    {
        $m = $x->m;
        $Ys = $m->Y - 1;
        $sh = $x->sheet('dash', $x->t('n.dash'));
        $g = new Grid($x, $sh, 'dash');
        $g->title($x->t('dash.title'), $x->t('dash.sub'));
        $g->row = 8; // the four KPI tiles sit in rows 4-6
        $yidx = $x->calRng('yidx');
        $yend = $x->calRng('yend');
        $study = $x->calRng('study');
        $R = fn (string $key) => $x->rng($key);
        $sumY = fn (string $key, int $y) => 'SUMIFS('.$R($key).','.$yidx.','.$y.')';
        $endY = fn (string $key, int $y) => 'SUMPRODUCT(('.$yend.'=1)*('.$yidx.'='.$y.')*'.$R($key).')';
        $c = fn (int $y) => Sheet::col(Ctx::FIRST + $y);

        // a header and rows over the study's years only
        $header = function (string $rowKey) use ($g, $x, $sh, $Ys) {
            $r = $g->row;
            $g->register($rowKey, $r);
            $sh->set($r, 1, $x->t('c.year'), $x->s('hdr_l'))->set($r, 2, '', $x->s('hdr'))->set($r, 3, '', $x->s('hdr'))->set($r, 4, '', $x->s('hdr'));
            for ($y = 0; $y < $Ys; $y++) {
                $sh->formula($r, Ctx::FIRST + $y, 'Z_BaseYear+'.$y, $x->s('hdr_int'));
            }
            $g->row++;
        };
        $yr = function (string $key, string $label, callable $f, string $style = 'num', ?string $note = null, bool $bold = false) use ($g, $x, $sh, $Ys) {
            $r = $g->row;
            $g->register($key, $r);
            $sh->set($r, 1, $label, $x->s($bold ? 'label_b' : 'label_i'));
            if ($note !== null) {
                $sh->set($r, 2, $note, $x->s('note'));
            }
            for ($y = 0; $y < $Ys; $y++) {
                $sh->formula($r, Ctx::FIRST + $y, $f($y), $x->s($style));
            }
            $g->row++;

            return $r;
        };
        $cell = fn (string $key, int $y) => $c($y).$g->rowOf($key);
        $range = fn (string $key) => '$'.$c(0).'$'.$g->rowOf($key).':$'.$c($Ys - 1).'$'.$g->rowOf($key);
        $scalar = function (string $key, string $label, string $formula, string $style = 'num', ?string $note = null) use ($g, $x, $sh) {
            $r = $g->row;
            $g->register($key, $r);
            $sh->set($r, 1, $label, $x->s('label_i'));
            if ($note !== null) {
                $sh->set($r, 2, $note, $x->s('note'));
            }
            $sh->formula($r, Ctx::FIRST, $formula, $x->s($style));
            $x->cells['dash.'.$key] = $x->pre('dash').'$'.Sheet::col(Ctx::FIRST).'$'.$r;
            $g->row++;

            return $r;
        };
        $pctOf = fn (string $key, string $rev) => fn (int $y) => 'IF('.$cell($rev, $y).'=0,0,'.$cell($key, $y).'/'.$cell($rev, $y).'*100)';

        // ── revenue and profit ──
        $g->section($x->t('dash.revProfit'));
        $header('yh');
        $yr('rev', $x->t('is.rev'), fn ($y) => $sumY('is.rev', $y), 'num', null, true);
        $yr('grow', $x->t('dash.growth'), fn ($y) => $y === 0 ? '0' : 'IF('.$cell('rev', $y - 1).'=0,0,('.$cell('rev', $y).'-'.$cell('rev', $y - 1).')/'.$cell('rev', $y - 1).'*100)', 'pct');
        $yr('accu', $x->t('dash.accumulated'), fn ($y) => ($y === 0 ? '' : $cell('accu', $y - 1).'+').$cell('rev', $y));
        foreach (['gp' => 'is.gp', 'ebitda' => 'is.ebitda', 'ebit' => 'is.ebit', 'net' => 'is.net'] as $k => $src) {
            $yr($k, $x->t($src), fn ($y) => $sumY($src, $y), 'num', null, true);
            $yr($k.'P', $x->t('dash.pctRev'), $pctOf($k, 'rev'), 'pct');
        }
        $g->blank();

        // ── costs as % of revenue ──
        $g->section($x->t('dash.costs'));
        $header('yh2');
        foreach (['cRm' => 'is.cgRm', 'cDl' => 'is.cgDl', 'cOh' => 'is.cgOh', 'cMkt' => 'is.xMkt', 'cSales' => 'is.xSales', 'cGen' => 'is.xGen'] as $k => $src) {
            $yr($k, $x->t($src), fn ($y) => $sumY($src, $y), 'num', null, true);
            $yr($k.'P', $x->t('dash.pctRev'), $pctOf($k, 'rev'), 'pct');
        }
        $g->blank();

        // ── cash in and out ──
        $g->section($x->t('dash.cash'));
        $header('yh3');
        $yr('cIn', $x->t('cf.in'), fn ($y) => $sumY('cf.in', $y));
        $yr('cOut', $x->t('cf.out'), fn ($y) => $sumY('cf.out', $y));
        $yr('cAcc', $x->t('dash.cashAcc'), fn ($y) => ($y === 0 ? '' : $cell('cAcc', $y - 1).'+').$cell('cIn', $y).'-'.$cell('cOut', $y), 'num', null, true);
        $g->blank();

        // ── discounted cash flow ──
        $g->section($x->t('dash.dcf'));
        $header('yh4');
        $bsPre = $x->pre('bs');
        $bsYear = fn (string $key, int $y) => $bsPre.$c($y).$x->rowOf('bs.'.$key);
        $yr('dEbit', $x->t('is.ebit'), fn ($y) => $cell('ebit', $y));
        $yr('dTax', $x->t('dash.taxEbit'), fn ($y) => 'IF('.$cell('dEbit', $y).'>0,'.$cell('dEbit', $y).'*Z_Tax/100,0)', 'num', $x->t('dash.taxEbitNote'));
        $yr('dDep', $x->t('is.depn'), fn ($y) => $sumY('is.depn', $y));
        $yr('dWc', $x->t('bs.wcNet'), fn ($y) => $bsYear('wcNet', $y));
        $yr('dCapex', $x->t('dash.capex'), fn ($y) => $sumY('cf.outFa', $y).'+'.$sumY('cf.outRepl', $y), 'num', $x->t('dash.capexNote'));
        $yr('dFcf', $x->t('dash.fcf'), fn ($y) => $cell('dEbit', $y).'+'.$cell('dDep', $y).'+'.$cell('dWc', $y).'-'.$cell('dTax', $y).'-'.$cell('dCapex', $y), 'num', $x->t('dash.fcfNote'), true);
        $g->blank();
        // cost of debt: the interest of every asset's loan, weighted by the size of that loan
        $w = [];
        $wr = [];
        foreach ($m->assets as $j => $_) {
            $p = 'fa.f'.$j.'_';
            $weight = '('.$x->name($p.'total').'*(100-'.$x->name($p.'eqPct').'))';
            $w[] = $weight;
            $wr[] = $weight.'*'.$x->name($p.'rate').'/100';
        }
        $scalar('cod', $x->t('dash.cod'), $w === [] ? '0' : 'IF('.implode('+', $w).'=0,0,('.implode('+', $wr).')/('.implode('+', $w).'))', 'pctf', $x->t('dash.codNote'));
        $scalar('g', $x->t('study.growth'), 'Z_Growth/100', 'pctf');
        $scalar('ret', $x->t('study.return'), 'Z_Return/100', 'pctf');
        $yr('wacc', $x->t('dash.wacc'), fn ($y) => $bsPre.$c($y).$x->rowOf('bs.fEq').'/100*'.$x->name('dash.ret').'+'.$bsPre.$c($y).$x->rowOf('bs.fDebt').'/100*'.$x->name('dash.cod'), 'pctf', $x->t('dash.waccNote'));
        $last = $Ys - 1;
        $yr('tv', $x->t('dash.tv'), fn ($y) => $y === $last ? 'IF('.$cell('wacc', $y).'>'.$x->name('dash.g').','.$cell('dFcf', $y).'*(1+'.$x->name('dash.g').')/('.$cell('wacc', $y).'-'.$x->name('dash.g').'),0)' : '0', 'num', $x->t('dash.tvNote'));
        $yr('wt', $x->t('dash.withTv'), fn ($y) => $cell('dFcf', $y).'+'.$cell('tv', $y), 'num', null, true);
        $yr('df', $x->t('dash.df'), fn ($y) => '(1+'.$cell('wacc', $y).')^'.($y + 1), 'num2');
        $yr('disc', $x->t('dash.disc'), fn ($y) => $cell('wt', $y).'/'.$cell('df', $y));
        $g->blank();
        $scalar('npv', $x->t('dash.npv'), 'SUM('.$range('disc').')', 'ok');
        $scalar('tvok', $x->t('dash.tvOk'), 'IF('.$cell('wacc', $last).'>'.$x->name('dash.g').',1,0)', 'flag', $x->t('dash.tvOkNote'));
        $scalar('tvpv', $x->t('dash.tvPv'), $cell('tv', $last).'/'.$cell('df', $last), 'num');
        $scalar('irr', $x->t('dash.irr'), 'IFERROR(IRR('.$range('wt').')*100,"")', 'pct', $x->t('dash.irrNote'));
        $g->blank();

        // ── ratios ──
        $g->section($x->t('dash.ratios'));
        $header('yh5');
        $div = fn (string $a, string $b, int $y) => 'IF('.$b.'=0,0,'.$a.'/'.$b.')';
        $yr('rTca', $x->t('bs.tca'), fn ($y) => $endY('bs.tca', $y));
        $yr('rTcl', $x->t('bs.tcl'), fn ($y) => $endY('bs.tcl', $y));
        $yr('rCur', $x->t('dash.current'), fn ($y) => $div($cell('rTca', $y), $cell('rTcl', $y), $y), 'num2');
        $yr('rQuick', $x->t('dash.quick'), fn ($y) => $div('('.$endY('bs.cash', $y).'+'.$endY('bs.rec', $y).')', $cell('rTcl', $y), $y), 'num2');
        $yr('rCash', $x->t('dash.cashRatio'), fn ($y) => $div($endY('bs.cash', $y), $cell('rTcl', $y), $y), 'num2');
        $yr('rWc', $x->t('dash.workingCapital'), fn ($y) => $cell('rTca', $y).'-'.$cell('rTcl', $y));
        $months = fn (int $y) => 'SUMPRODUCT(('.$yidx.'='.$y.')*('.$study.'=1))';
        $cogs = fn (int $y) => '('.$cell('rev', $y).'-'.$cell('gp', $y).')';
        $days = fn (string $balance, string $base, int $y) => 'IF(OR('.$months($y).'=0,'.$base.'=0),0,'.$sumY($balance, $y).'/'.$months($y).'/'.$base.'*('.$months($y).'*30))';
        $yr('rDso', $x->t('dash.dso'), fn ($y) => $days('bs.rec', $cell('rev', $y), $y), 'num', $x->t('dash.daysNote'));
        // inventory = raw materials + finished goods; summed in the days formula
        $yr('rDio', $x->t('dash.dio'), fn ($y) => 'IF(OR('.$months($y).'=0,'.$cogs($y).'=0),0,('.$sumY('bs.rm', $y).'+'.$sumY('bs.fg', $y).')/'.$months($y).'/'.$cogs($y).'*('.$months($y).'*30))', 'num', $x->t('dash.daysNote'));
        $yr('rDpo', $x->t('dash.dpo'), fn ($y) => $days('bs.sp', $cogs($y), $y), 'num', $x->t('dash.daysNote'));
        $yr('rCcc', $x->t('dash.ccc'), fn ($y) => $cell('rDso', $y).'+'.$cell('rDio', $y).'-'.$cell('rDpo', $y), 'num', null, true);
        $g->blank();

        // ── investment ──
        $g->section($x->t('dash.invest'));
        $fa = [];
        foreach ($m->assets as $j => $_) {
            $fa[] = $x->name('fa.f'.$j.'_total');
        }
        $scalar('iFa', $x->t('dash.iFa'), $fa === [] ? '0' : implode('+', $fa));
        $scalar('iWc', $x->t('dash.iWc'), 'SUM('.$R('cf.inj').')', 'num', $x->t('dash.iWcNote'));
        $scalar('iEq', $x->t('dash.iEq'), 'SUM('.$R('cf.inEq').')+'.$x->name('dash.iWc'));
        $scalar('iLoans', $x->t('dash.iLoans'), 'SUM('.$R('cf.inLoan').')');
        $scalar('iTotal', $x->t('dash.iTotal'), $x->name('dash.iFa').'+'.$x->name('dash.iWc'), 'num', $x->t('dash.iTotalNote'));
        $g->blank();

        // ── payback ──
        $g->section($x->t('dash.payback'));
        $scalar('pS', $x->t('dash.pS'), 'Z_Months', 'int');
        $scalar('pH', $x->t('dash.pH'), 'IF('.$x->name('dash.pS').'>=240,'.$x->name('dash.pS').','.$x->name('dash.pS').'+12*ROUNDUP((240-'.$x->name('dash.pS').')/12,0))', 'int', $x->t('dash.pHNote'));
        $tbl = $g->row + 7;
        $tblLast = $tbl + self::PAYBACK_ROWS - 1;
        $payM = $x->name('dash.pS');
        $scalar('pEq', $x->t('dash.pEq'), $x->name('dash.iEq'), 'num');
        $scalar('pMonth', $x->t('dash.pMonth'), 'IFERROR(MATCH(1,$E$'.$tbl.':$E$'.$tblLast.',0)-1,"")', 'int', $x->t('dash.pMonthNote'));
        $scalar('pMonths', $x->t('dash.pMonths'), 'IF('.$x->name('dash.pMonth').'="","",'.$x->name('dash.pMonth').'+1)', 'int');
        $scalar('pYears', $x->t('dash.pYears'), 'IF('.$x->name('dash.pMonth').'="","",ROUND(('.$x->name('dash.pMonth').'+1)/12,1))', 'num2');
        $scalar('pDate', $x->t('dash.pDate'), 'IF('.$x->name('dash.pMonth').'="","",EDATE(Z_Start,'.$x->name('dash.pMonth').'))', 'date');
        $g->blank();
        $r = $g->row;
        foreach (['dash.t.ok', 'dash.t.month', 'dash.t.flow', 'dash.t.cum'] as $i => $lk) {
            $sh->set($r, 5 + $i, $x->t($lk), $x->s('hdr'));
        }
        $sh->height($r, 32);
        $g->row++;
        if ($g->row !== $tbl) {
            throw new \RuntimeException('Payback table misplaced: '.$g->row.' vs '.$tbl);
        }
        $cOut = $R('cf.out');
        $cCol = $R('cf.inColl');
        $cRcv = $R('cf.inRcv');
        $cDeb = $R('cf.inDeb');
        for ($i = 0; $i < self::PAYBACK_ROWS; $i++) {
            $r = $tbl + $i;
            $S = $x->name('dash.pS');
            $H = $x->name('dash.pH');
            $pick = fn (string $rng) => 'INDEX('.$rng.',1,$F'.$r.'+1)';
            $sh->formula($r, 5, "IF(AND(F$r<$H,H$r>=0,H$r>=".$x->name('dash.pEq')."),1,0)", $x->s('flag'));
            $sh->set($r, 6, (float) $i, $x->s('flag'));
            $own = "IF(F$r<$S,".$pick($cCol).'+'.$pick($cRcv).'+'.$pick($cDeb).'-'.$pick($cOut).',IF(F'.$r.'<'.$H.','.($i >= 12 ? 'G'.($r - 12).'*(1+'.$x->name('dash.g').')' : '0').',0))';
            $sh->formula($r, 7, $own, $x->s('num'));
            $sh->formula($r, 8, ($i === 0 ? '' : 'H'.($r - 1).'+')."G$r", $x->s('num'));
        }
        $g->row = $tblLast + 1;

        // ── the KPI tiles at the top ──
        $tiles = [
            [$x->t('dash.kNpv'), $x->name('dash.npv'), 'kpi'],
            [$x->t('dash.kIrr'), $x->name('dash.irr'), 'kpi_pct'],
            [$x->t('dash.kPayback'), $x->name('dash.pYears'), 'kpi'],
            [$x->t('dash.kInvest'), $x->name('dash.iTotal'), 'kpi'],
        ];
        foreach ($tiles as $i => [$label, $ref, $style]) {
            $c1 = Ctx::FIRST + $i * 3;
            $sh->set(4, $c1, $label, $x->s('kpi_lbl'))->set(4, $c1 + 1, '', $x->s('kpi_lbl'))->set(4, $c1 + 2, '', $x->s('kpi_lbl'));
            $sh->merge(4, $c1, 4, $c1 + 2);
            $sh->formula(5, $c1, 'IF('.$ref.'="","n/a",'.$ref.')', $x->s($style));
            $sh->set(5, $c1 + 1, '', $x->s($style))->set(5, $c1 + 2, '', $x->s($style));
            $sh->merge(5, $c1, 6, $c1 + 2);
            $sh->height(4, 24);
            $sh->height(5, 30);
        }
        $sh->freeze(3, Ctx::FIRST);
    }
}
