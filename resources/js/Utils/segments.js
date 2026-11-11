// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — the arithmetic of sales channels and export markets
//  Location: resources/js/Utils/segments.js
//
//  What the product screen shows while you type. The server works the same figures out again when you save
//  (App\Calculations\SegmentCalculator); tests/js/segments.spec.js checks that the two agree on the agreed examples.
//    channel quantity = local quantity × share %          channel price = base price × (1 + difference %)
//    foreign price    = first price, then previous × (1 + growth %) rounded to 2 decimals
//    export price     = foreign price × the exchange rate of the year
// ══════════════════════════════════════════════════════════════════
import { num } from '@/Utils/productPlan';

export const segmentQuantity = (groupQuantity, sharePercent) => (num(groupQuantity) * num(sharePercent)) / 100;

export const channelPrice = (basePrice, differencePercent) => num(basePrice) * (1 + num(differencePercent) / 100);

/** Prices in the market's currency for the years in order: the first year is typed, the others grow from the year before (2 decimals). */
export function foreignPrices(firstPrice, growth, years) {
    const result = {};
    let previous = null;

    years.forEach((year) => {
        previous = previous === null ? num(firstPrice) : Math.round(previous * (1 + num(growth[year]) / 100) * 100) / 100;
        result[year] = previous;
    });

    return result;
}

export const exportPrice = (foreignPrice, rate) => num(foreignPrice) * num(rate);

/** Total of the shares of one year over the rows (channels or markets). */
export const shareTotal = (rows, year) => rows.reduce((sum, row) => sum + num(row.allocation_percentages[year]), 0);

/** Average price of a year: total value divided by total quantity (0 when nothing is sold). */
export function averagePrice(lines) {
    const quantity = lines.reduce((s, l) => s + l.quantity, 0);

    return quantity > 0 ? lines.reduce((s, l) => s + l.value, 0) / quantity : 0;
}

/** Local lines of a year: one per channel (quantity, price, value). */
export function localLines(rows, year, localQuantity, basePrice) {
    return rows.map((row) => {
        const quantity = segmentQuantity(localQuantity, row.allocation_percentages[year]);
        const price = channelPrice(basePrice, row.price_differences[year]);

        return { quantity, price, value: quantity * price };
    });
}

/** Export lines of a year: one per market (quantity, foreign price, rate, value in both currencies). */
export function exportLines(rows, year, years, exportQuantity, fx, marketCurrency) {
    return rows.map((row) => {
        const quantity = segmentQuantity(exportQuantity, row.allocation_percentages[year]);
        const foreign = foreignPrices(row.first_foreign_price, row.foreign_growth_rates, years)[year] ?? 0;
        const rate = num((fx[marketCurrency(row)] ?? {})[year]);

        return { quantity, foreign, rate, foreignValue: quantity * foreign, value: quantity * exportPrice(foreign, rate) };
    });
}
