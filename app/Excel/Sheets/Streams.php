<?php

namespace App\Excel\Sheets;

use App\Excel\Ctx;
use App\Excel\Grid;
use App\Excel\K;

// The collection (or payment) statement of one stream of amounts: what falls due, how it is settled under a policy for the first
// operation year and one for the later years, the VAT and withholding tax, and the balance still open. Used for the sales of a
// product (customers) and for the purchases of a raw material (suppliers). Same rules as CollectionPolicy::statement().
final class Streams
{
    /**
     * Writes the rows (call right after a section / month header). Row keys are $p.'base', 'vat', 'whAmt', 'av', 'avF', 'avL', 'whF', 'whL', 'cav', 'cwh', 'net', 'beg', 'due', 'end'.
     *
     * @param  string  $baseKey  row key of the amounts before VAT on this sheet
     * @param  string  $vat      cell (or number) with the VAT %      $wh  the withholding %
     * @param  string  $polF     policy cell-key prefix of the first operation year ("sheet.pLF")   $polL  of the later years
     * @param  array<string,string>  $labels  label keys by row
     */
    public static function build(Ctx $x, Grid $g, string $p, string $baseKey, string $vat, string $wh, string $polF, string $polL, array $labels): void
    {
        $cellF = fn (string $n) => $x->name($polF.'.'.$n);
        $cellL = fn (string $n) => $x->name($polL.'.'.$n);
        $g->plan([$p.'base', $p.'vat', $p.'whAmt', $p.'av', $p.'avF', $p.'avL', $p.'whF', $p.'whL', $p.'cav', $p.'cwh', $p.'net', $p.'beg', $p.'due', $p.'end']);
        $g->line($p.'base', $x->t($labels['base']), fn (K $k) => $k->r($baseKey), ['total' => 'sum']);
        $g->line($p.'vat', $x->t($labels['vat']), fn (K $k) => "{$k->r($p.'base')}*$vat/100", ['total' => 'sum']);
        $g->line($p.'whAmt', $x->t($labels['wh']), fn (K $k) => "{$k->r($p.'base')}*$wh/100", ['total' => 'sum']);
        $g->line($p.'av', $x->t($labels['av']), fn (K $k) => "{$k->r($p.'base')}+{$k->r($p.'vat')}", ['total' => 'sum']);
        $g->line($p.'avF', $x->t($labels['avF']), fn (K $k) => "{$k->r($p.'av')}*{$k->cal('first')}");
        $g->line($p.'avL', $x->t($labels['avL']), fn (K $k) => "{$k->r($p.'av')}*{$k->cal('later')}");
        $g->line($p.'whF', $x->t($labels['whF']), fn (K $k) => "{$k->r($p.'whAmt')}*{$k->cal('first')}");
        $g->line($p.'whL', $x->t($labels['whL']), fn (K $k) => "{$k->r($p.'whAmt')}*{$k->cal('later')}");
        $collect = function (K $k, string $F, string $L) use ($x, $g, $cellF, $cellL): string {
            $fR = $g->rng($F);
            $lR = $g->rng($L);

            return "{$k->cal('op')}*({$cellF('cash')}*{$k->r($F)}+{$cellF('r1')}*{$x->shift($fR, $cellF('lag1'), $k->c)}+{$cellF('r2')}*{$x->shift($fR, $cellF('lag2'), $k->c)}"
                ."+{$cellL('cash')}*{$k->r($L)}+{$cellL('r1')}*{$x->shift($lR, $cellL('lag1'), $k->c)}+{$cellL('r2')}*{$x->shift($lR, $cellL('lag2'), $k->c)})/100";
        };
        $g->line($p.'cav', $x->t($labels['cav']), fn (K $k) => $collect($k, $p.'avF', $p.'avL'), ['total' => 'sum']);
        $g->line($p.'cwh', $x->t($labels['cwh']), fn (K $k) => $collect($k, $p.'whF', $p.'whL'), ['total' => 'sum']);
        $g->line($p.'net', $x->t($labels['net']), fn (K $k) => "{$k->r($p.'cav')}-{$k->r($p.'cwh')}", ['total' => 'sum', 'bold' => true]);
        $g->line($p.'beg', $x->t($labels['beg']), fn (K $k) => $k->pr($p.'end'));
        $g->line($p.'due', $x->t($labels['due']), fn (K $k) => "{$k->r($p.'beg')}+{$k->r($p.'av')}");
        $g->line($p.'end', $x->t($labels['end']), fn (K $k) => "{$k->cal('op')}*({$k->r($p.'due')}-{$k->r($p.'net')}-{$k->r($p.'cwh')})", ['bold' => true]);
    }
}
