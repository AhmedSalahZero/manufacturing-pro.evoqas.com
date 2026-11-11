import { describe, expect, it } from 'vitest';
import { averagePrice, channelPrice, exportLines, exportPrice, foreignPrices, localLines, segmentQuantity, shareTotal } from '@/Utils/segments';

// The agreed examples of the scope of work (section 4): capacity 12,000 tons, local 6,000 + export 6,000, 1 USD = 50 EGP, 1 EUR = 55 EGP.
const channels = [
    { allocation_percentages: { 0: 50 }, price_differences: { 0: 10 } },
    { allocation_percentages: { 0: 30 }, price_differences: { 0: 0 } },
    { allocation_percentages: { 0: 20 }, price_differences: { 0: -5 } },
];
const markets = [
    { currency: 'usd', allocation_percentages: { 0: 50 }, first_foreign_price: 230, foreign_growth_rates: {} },
    { currency: 'usd', allocation_percentages: { 0: 30 }, first_foreign_price: 220, foreign_growth_rates: {} },
    { currency: 'euro', allocation_percentages: { 0: 20 }, first_foreign_price: 190, foreign_growth_rates: {} },
];
const fx = { usd: { 0: 50 }, euro: { 0: 55 } };

describe('sales channels and markets: what the screen works out while you type', () => {
    it('splits the quantity and prices each channel', () => {
        expect(segmentQuantity(6000, 50)).toBe(3000);
        expect(channelPrice(10000, 10)).toBeCloseTo(11000, 6);
        expect(channelPrice(10000, -5)).toBeCloseTo(9500, 6);
        expect(channelPrice('', 10)).toBe(0);
    });

    it('gives the agreed local values', () => {
        const lines = localLines(channels, 0, 6000, 10000);
        expect(lines.map((l) => Math.round(l.value))).toEqual([33000000, 18000000, 11400000]);
        expect(lines.reduce((s, l) => s + l.value, 0)).toBeCloseTo(62400000, 4);
        expect(averagePrice(lines)).toBeCloseTo(10400, 6);
    });

    it('gives the agreed export values in both currencies', () => {
        const lines = exportLines(markets, 0, [0], 6000, fx, (m) => m.currency);
        expect(lines.map((l) => Math.round(l.value))).toEqual([34500000, 19800000, 12540000]);
        expect(lines[0].foreignValue).toBe(690000);
        expect(lines[2].foreignValue).toBe(228000);
        expect(lines[2].rate).toBe(55);
        expect(exportPrice(230, 50)).toBe(11500);
    });

    it('the average price of the whole product is 10,770', () => {
        const all = [...localLines(channels, 0, 6000, 10000), ...exportLines(markets, 0, [0], 6000, fx, (m) => m.currency)];
        expect(averagePrice(all)).toBeCloseTo(10770, 6);
    });

    it('grows the foreign price from the year before, to 2 decimals', () => {
        expect(foreignPrices(10, { 1: 3.333, 2: 3.333, 3: 3.333 }, [0, 1, 2, 3])).toEqual({ 0: 10, 1: 10.33, 2: 10.67, 3: 11.03 });
        expect(foreignPrices('', {}, [0, 1])).toEqual({ 0: 0, 1: 0 });
    });

    it('adds up the shares of a year', () => {
        expect(shareTotal(channels, 0)).toBe(100);
        expect(shareTotal([{ allocation_percentages: { 0: '' } }], 0)).toBe(0);
        expect(averagePrice([])).toBe(0);
    });
});
