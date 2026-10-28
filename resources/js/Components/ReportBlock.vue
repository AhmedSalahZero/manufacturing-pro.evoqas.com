<!--
  ZAVERO Manufacturing Pro — One read-only table or text of the printable report
  Location: resources/js/Components/ReportBlock.vue
  Draws a block built by App\Services\ReportInputsService: a title, then a table (headers and rows) or a paragraph.
  A cell is a plain string or number, { k, p, sfx } (a translated label), { pct }, { n, d } or { text }.
-->
<script setup>
import { useI18n } from '@/lang/i18n';
import { formatNumber } from '@/Utils/format';

defineProps({ block: { type: Object, required: true } });

const { t } = useI18n();

const dec = (v) => (Number.isInteger(v) ? 0 : Math.round(v * 10) === v * 10 ? 1 : 2);

const show = (c) => {
    if (c === null || c === undefined || c === '') return '';
    if (typeof c === 'number') return formatNumber(c, Number.isInteger(c) ? 0 : 2);
    if (typeof c === 'string') return c;
    if (c.k !== undefined) return t(c.k, c.p ?? {}) + (c.sfx ?? '');
    if (c.pct !== undefined) return `${formatNumber(c.pct, dec(c.pct))}%`;
    if (c.n !== undefined) return formatNumber(c.n, c.d ?? 0);
    if (c.text !== undefined) return c.text;
    return '';
};
const isNumber = (c) => typeof c === 'number' || (typeof c === 'object' && c !== null && (c.pct !== undefined || c.n !== undefined));
</script>

<template>
    <div class="rblock" data-report-block>
        <h4 v-if="block.title" class="rtitle">{{ show(block.title) }}</h4>
        <p v-if="block.type === 'text'" class="rtext" style="white-space:pre-line">{{ block.text }}</p>
        <div v-else class="tw nomax">
            <table class="stm">
                <thead>
                    <tr><th v-for="(h, i) in block.headers" :key="i" :class="{ num: i > 0 }">{{ show(h) }}</th></tr>
                </thead>
                <tbody>
                    <tr v-for="(row, r) in block.rows" :key="r" :class="{ totalrow: block.total && r === block.rows.length - 1 }">
                        <td v-for="(c, i) in row" :key="i" :class="{ num: isNumber(c) }" :dir="isNumber(c) ? 'ltr' : undefined">{{ show(c) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>

<style scoped>
.rblock { margin: 0 0 1.1rem; }
.rtitle { margin: 0 0 0.4rem; font-size: 0.95rem; color: var(--ink); }
.totalrow td { border-top: 2px solid var(--line); font-weight: 700; }
td.num, th.num { text-align: end; white-space: nowrap; }
</style>
