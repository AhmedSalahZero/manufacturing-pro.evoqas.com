<?php

namespace App\Excel\Sheets;

use App\Excel\Ctx;
use App\Excel\Grid;
use App\Excel\K;

// The Product Costing page: for every product, what is spent each month on raw materials, direct labour, overheads and the depreciation of the
// machines, how it is shared out over the units available (weighted average), the cost of the units sold and the value of the stock left.
final class CostingSheet
{
    public const TYPES = ['rm', 'dl', 'oh', 'dp'];

    public static function build(Ctx $x): void
    {
        $m = $x->m;
        if ($m->products === []) {
            return;
        }
        $sh = $x->sheet('cost', $x->t('n.cost'));
        $g = new Grid($x, $sh, 'cost');
        $g->title($x->t('cost.title'), $x->t('cost.sub'));
        $depRows = function (K $k, int $i) use ($x, $m) {
            $parts = [];
            foreach ($m->assets as $j => $_) {
                $parts[] = $x->x('fa.f'.$j.'_a'.$i, $k->c);
            }
            if (! $m->new) {
                $parts[] = $x->x('ob.t_p'.$i, $k->c);
            }

            return $parts === [] ? '0' : implode('+', $parts);
        };

        foreach ($m->products as $i => $pr) {
            $pk = SalesSheet::key($i);
            $q = 'p'.$i.'_';
            $g->section($x->t('cost.block', $i + 1, (string) ($pr['name'] ?? '')));
            $g->monthHeader();
            $keys = [];
            foreach (self::TYPES as $t) {
                foreach (['sp', 'av', 'pu', 'cg', 'en'] as $s) {
                    $keys[] = $q.$t.$s;
                }
                $keys[] = '_';
            }
            $keys[] = $q.'cgT';
            $keys[] = $q.'enT';
            $g->plan($keys);
            $opening = fn (string $short) => $m->new ? '0' : 'IF(Z_NewCo=1,0,'.$x->name($pk.'.fgv_'.$short).')';
            $spent = [
                'rm' => fn (K $k) => $m->rms === [] ? '0' : $k->x($pk.'.consT'),
                'dl' => fn (K $k) => $m->staff === [] ? '0' : $k->x('mp.adl'.$i),
                'oh' => fn (K $k) => ($m->staff === [] ? '0' : $k->x('mp.aoh'.$i)).'+'.($m->expenses === [] ? '0' : $k->x('exp.toP'.$i)).'+'.$depRows($k, $i),
                'dp' => fn (K $k) => $depRows($k, $i),
            ];
            foreach (self::TYPES as $t) {
                $g->line($q.$t.'sp', $x->t('cost.sp.'.$t), $spent[$t], ['total' => 'sum', 'bold' => true]);
                $g->line($q.$t.'av', $x->t('cost.av'), fn (K $k) => $k->pr($q.$t.'en').'+'.$k->r($q.$t.'sp'));
                $g->line($q.$t.'pu', $x->t('cost.pu'), fn (K $k) => 'IF('.$k->x($pk.'.avail').'=0,0,'.$k->r($q.$t.'av').'/'.$k->x($pk.'.avail').')', ['style' => 'num2']);
                $g->line($q.$t.'cg', $x->t('cost.cg'), fn (K $k) => $k->r($q.$t.'pu').'*'.$k->x($pk.'.sold'), ['total' => 'sum', 'bold' => true]);
                $g->line($q.$t.'en', $x->t('cost.en'), fn (K $k) => $k->r($q.$t.'av').'-'.$k->r($q.$t.'cg'), ['bold' => true, 'before' => $t === 'dp' ? '0' : $opening($t === 'oh' ? 'oh' : $t)]);
                $g->blank();
            }
            $g->line($q.'cgT', $x->t('cost.cgT'), fn (K $k) => $k->r($q.'rmcg').'+'.$k->r($q.'dlcg').'+'.$k->r($q.'ohcg'), ['total' => 'sum', 'bold' => true, 'note' => $x->t('cost.cgTNote')]);
            $g->line($q.'enT', $x->t('cost.enT'), fn (K $k) => $k->r($q.'rmen').'+'.$k->r($q.'dlen').'+'.$k->r($q.'ohen'), ['bold' => true]);
            $g->blank(2);
        }

        $g->section($x->t('cost.totals'));
        $g->monthHeader();
        $sum = function (string $suffix) use ($m) {
            return function (K $k) use ($m, $suffix) {
                $parts = [];
                foreach ($m->products as $i => $_) {
                    $parts[] = $k->r('p'.$i.'_'.$suffix);
                }

                return implode('+', $parts);
            };
        };
        $g->line('t_rm', $x->t('cost.t.rm'), $sum('rmcg'), ['total' => 'sum']);
        $g->line('t_dl', $x->t('cost.t.dl'), $sum('dlcg'), ['total' => 'sum']);
        $g->line('t_oh', $x->t('cost.t.oh'), $sum('ohcg'), ['total' => 'sum']);
        $g->line('t_dp', $x->t('cost.t.dp'), $sum('dpcg'), ['total' => 'sum', 'note' => $x->t('cost.t.dpNote')]);
        $g->line('t_fg', $x->t('cost.t.fg'), $sum('enT'), ['bold' => true]);
        $sh->freeze(4, Ctx::FIRST);
    }
}
