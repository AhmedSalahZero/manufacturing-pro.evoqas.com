<!--
  ZAVERO Manufacturing Pro — ZChart (a small bar / line chart drawn as SVG)
  Location: resources/js/Components/ZChart.vue
  Bars and lines over the same labels (usually the years). A series can sit on the right-hand scale (`secondary`, e.g. a % line
  over bars of money). Uses the approved chart styles (charts.css); hover a column to see all of its values.
    series: [{ name, values: [n, …], kind: 'bar' | 'line', color: 'var(--c1)', secondary?: true }]
-->
<script setup>
import { computed } from 'vue';
import { formatNumber } from '@/Utils/format';

const props = defineProps({
    title: { type: String, default: '' },
    sub: { type: String, default: '' },
    labels: { type: Array, required: true },
    series: { type: Array, required: true },
    percentSecondary: { type: Boolean, default: true },
    percentPrimary: { type: Boolean, default: false },
});

const W = 640;
const H = 230;
const pad = { top: 14, bottom: 30, left: 58 };
const hasSecondary = computed(() => props.series.some((s) => s.secondary));
const right = computed(() => (hasSecondary.value ? 52 : 14));
const inner = computed(() => ({ w: W - pad.left - right.value, h: H - pad.top - pad.bottom }));

const nice = (min, max) => {
    if (min === max) { max = min + 1; }
    const span = max - min;
    const step = 10 ** Math.floor(Math.log10(span / 4));
    const err = span / 4 / step;
    const unit = (err >= 5 ? 10 : err >= 2.5 ? 5 : err >= 1.2 ? 2 : 1) * step;
    const lo = Math.floor(min / unit) * unit;
    const hi = Math.ceil(max / unit) * unit;
    const ticks = [];
    for (let v = lo; v <= hi + unit / 2; v += unit) ticks.push(Math.round(v / unit) * unit);

    return { lo, hi, ticks };
};

const scaleOf = (items) => {
    const values = items.flatMap((s) => s.values.map(Number));
    if (!values.length) return nice(0, 1);
    const hasBars = items.some((s) => s.kind === 'bar');

    return nice(Math.min(0, ...values, hasBars ? 0 : Infinity), Math.max(0, ...values));
};
const left = computed(() => scaleOf(props.series.filter((s) => !s.secondary)));
const second = computed(() => scaleOf(props.series.filter((s) => s.secondary)));

const y = (v, scale) => pad.top + inner.value.h - ((v - scale.lo) / (scale.hi - scale.lo || 1)) * inner.value.h;
const x = (i) => pad.left + (inner.value.w / props.labels.length) * (i + 0.5);

const bars = computed(() => props.series.filter((s) => s.kind === 'bar'));
const bandWidth = computed(() => inner.value.w / props.labels.length);
const barWidth = computed(() => Math.min(46, (bandWidth.value * 0.72) / Math.max(1, bars.value.length)));
const barX = (i, k) => x(i) - (barWidth.value * bars.value.length) / 2 + k * barWidth.value;

const compact = (v) => {
    const a = Math.abs(v);
    const short = (x, unit) => `${formatNumber(x, Number.isInteger(Math.round(x * 10) / 10) && Math.round(x * 10) % 10 === 0 ? 0 : 1)}${unit}`;
    if (a >= 1e9) return short(v / 1e9, 'B');
    if (a >= 1e6) return short(v / 1e6, 'M');
    if (a >= 1e3) return short(v / 1e3, 'K');

    return formatNumber(v, a < 10 && a % 1 ? 1 : 0);
};
const tick = (v, secondary) => ((secondary ? props.percentSecondary : props.percentPrimary) ? `${formatNumber(v, 0)}%` : compact(v));
const tip = (v, s) => ((s.secondary ? props.percentSecondary : props.percentPrimary) ? `${formatNumber(v, 1)}%` : formatNumber(v, 0));

const linePath = (s) => s.values.map((v, i) => `${i ? 'L' : 'M'}${x(i).toFixed(1)},${y(Number(v), s.secondary ? second.value : left.value).toFixed(1)}`).join(' ');
// Month labels (2026-03) are shortened so twelve of them fit under the bars: 03/26
const short = (label) => (/^\d{4}-\d{2}$/.test(String(label)) ? `${String(label).slice(5)}/${String(label).slice(2, 4)}` : label);
const colour = (s, k) => s.color ?? `var(--c${(k % 8) + 1})`;
</script>

<template>
    <figure class="chart" :aria-label="title">
        <div class="chart-head">
            <div>
                <div v-if="title" class="chart-title">{{ title }}</div>
                <div v-if="sub" class="chart-sub">{{ sub }}</div>
            </div>
            <div v-if="series.length > 1" class="chart-leg">
                <span v-for="(s, k) in series" :key="s.name" :style="{ color: colour(s, k) }">
                    <i :class="s.kind === 'bar' ? 'sq' : ''"></i><b>{{ s.name }}</b>
                </span>
            </div>
        </div>
        <svg :viewBox="`0 0 ${W} ${H}`" role="img" dir="ltr">
            <g v-for="t in left.ticks" :key="`l${t}`">
                <line class="ch-grid" :x1="pad.left" :x2="W - right" :y1="y(t, left)" :y2="y(t, left)" />
                <text class="ch-t" :x="pad.left - 8" :y="y(t, left) + 4" text-anchor="end">{{ tick(t, false) }}</text>
            </g>
            <line class="ch-zero" :x1="pad.left" :x2="W - right" :y1="y(0, left)" :y2="y(0, left)" />
            <template v-if="hasSecondary">
                <text v-for="t in second.ticks" :key="`r${t}`" class="ch-t" :x="W - right + 8" :y="y(t, second) + 4">{{ tick(t, true) }}</text>
            </template>

            <g v-for="(s, k) in bars" :key="`b${s.name}`">
                <rect
                    v-for="(v, i) in s.values" :key="i" :x="barX(i, k)" :width="barWidth - 2" rx="2"
                    :y="Math.min(y(Number(v), left), y(0, left))" :height="Math.abs(y(Number(v), left) - y(0, left))"
                    :fill="colour(s, series.indexOf(s))"
                ><title>{{ labels[i] }} · {{ s.name }}: {{ tip(v, s) }}</title></rect>
            </g>
            <g v-for="s in series.filter((q) => q.kind === 'line')" :key="`ln${s.name}`">
                <path :d="linePath(s)" fill="none" :stroke="colour(s, series.indexOf(s))" stroke-width="2.5" stroke-linejoin="round" :stroke-dasharray="s.dashed ? '6 4' : undefined" />
                <circle v-for="(v, i) in s.values" :key="i" :cx="x(i)" :cy="y(Number(v), s.secondary ? second : left)" r="3.5" :fill="colour(s, series.indexOf(s))">
                    <title>{{ labels[i] }} · {{ s.name }}: {{ tip(v, s) }}</title>
                </circle>
            </g>

            <text v-for="(label, i) in labels" :key="`x${i}`" class="ch-cat" :x="x(i)" :y="H - 9" text-anchor="middle">{{ short(label) }}</text>
        </svg>
    </figure>
</template>
