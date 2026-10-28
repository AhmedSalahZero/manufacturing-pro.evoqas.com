// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — the arithmetic of the product sales table
//  Location: resources/js/Utils/productPlan.js
//
//  What the screen shows while you type (the server works the same figures out again when you save,
//  see App\Calculations\ProductSalesCalculator — the two must agree, tests/js/productPlan.spec.js checks it).
//    target quantity = capacity × target %          local quantity = target × local %
//    export quantity = target − local               price of a year = previous year's price × (1 + growth %), rounded
// ══════════════════════════════════════════════════════════════════

/** A typed value as a number ('' or text → 0). */
export const num = (value) => {
    const n = parseFloat(value);

    return Number.isFinite(n) ? n : 0;
};

export const targetQuantity = (capacity, targetPercent) => (num(capacity) * num(targetPercent)) / 100;

export const localQuantity = (capacity, targetPercent, localPercent) => (targetQuantity(capacity, targetPercent) * num(localPercent)) / 100;

export const exportQuantity = (capacity, targetPercent, localPercent) => targetQuantity(capacity, targetPercent) - localQuantity(capacity, targetPercent, localPercent);

export const exportPercent = (localPercent) => 100 - num(localPercent);

/** The price of a year grown from the year before, to a whole number (like the old screen). */
export const nextPrice = (previousPrice, growthPercent) => Math.round(num(previousPrice) * (1 + num(growthPercent) / 100));

/**
 * Prices of the following years after the price at position `from` changed (or its growth was typed).
 * @param {Object<number,string|number>} prices    year index → price
 * @param {Object<number,string|number>} growth    year index → growth %
 * @param {number[]} years                         the year indexes, in order
 * @param {number} from                            position (in `years`) of the first year to recalculate
 */
export function repriceFrom(prices, growth, years, from) {
    const result = { ...prices };

    for (let i = Math.max(from, 1); i < years.length; i++) {
        result[years[i]] = nextPrice(result[years[i - 1]], growth[years[i]]);
    }

    return result;
}

export const yearSales = (capacity, targetPercent, localPercent, localPrice, exportPrice) => (
    localQuantity(capacity, targetPercent, localPercent) * num(localPrice)
    + exportQuantity(capacity, targetPercent, localPercent) * num(exportPrice)
);

export const total = (values) => Object.values(values).reduce((sum, v) => sum + num(v), 0);

/** Percentages that must add up to 100 (the server allows a hundredth of a percent of rounding). */
export const addsUpTo100 = (...values) => Math.abs(values.flat().reduce((sum, v) => sum + num(v), 0) - 100) <= 0.01;

/** Opening inventory split: the value of each part. */
export const partValue = (inventoryValue, percent) => (num(inventoryValue) * num(percent)) / 100;
