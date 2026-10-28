// ZAVERO Manufacturing Pro — product sales table arithmetic (run: npm test)
// Location: tests/js/productPlan.spec.js
// The numbers are the ones of a real product of the previous ZAVERO (capacity 6000, 50 % sold, 80 % local, price 60,000, +10 % a year).
import { describe, expect, it } from 'vitest';
import { addsUpTo100, exportPercent, exportQuantity, localQuantity, nextPrice, num, partValue, repriceFrom, targetQuantity, total, yearSales } from '@/Utils/productPlan';

describe('quantities', () => {
    it('works out target, local and export quantities from the capacity', () => {
        expect(targetQuantity(6000, 50)).toBe(3000);
        expect(localQuantity(6000, 50, 80)).toBe(2400);
        expect(exportQuantity(6000, 50, 80)).toBe(600);
        expect(exportPercent(80)).toBe(20);
    });

    it('treats empty or wrong text as zero', () => {
        expect(num('')).toBe(0);
        expect(num('abc')).toBe(0);
        expect(num('12.5')).toBe(12.5);
        expect(targetQuantity('', 50)).toBe(0);
    });
});

describe('prices', () => {
    it('grows the price year by year and rounds like the old screen', () => {
        const years = [0, 1, 2, 3, 4, 5, 6];
        const growth = Object.fromEntries(years.map((y) => [y, 10]));
        const prices = repriceFrom({ 0: 60000 }, growth, years, 1);

        // 60000 → 66000 → 72600 → 79860 → 87846 → 96631 (96630.6 rounded) → 106294 (from the rounded 96631)
        expect(Object.values(prices)).toEqual([60000, 66000, 72600, 79860, 87846, 96631, 106294]);
    });

    it('keeps the years before the changed one', () => {
        const years = [0, 1, 2];
        const prices = repriceFrom({ 0: 100, 1: 150, 2: 0 }, { 0: 0, 1: 10, 2: 10 }, years, 2);

        expect(prices).toEqual({ 0: 100, 1: 150, 2: 165 });
        expect(nextPrice(100, 0)).toBe(100);
    });
});

describe('sales value and totals', () => {
    it('adds the local and the export sales', () => {
        expect(yearSales(6000, 50, 80, 60000, 50000)).toBe(2400 * 60000 + 600 * 50000);
    });

    it('adds up lists and checks the 100 % rule', () => {
        expect(total({ 0: 25, 1: '25', 2: 50 })).toBe(100);
        expect(addsUpTo100(25, 25, 25, 25)).toBe(true);
        expect(addsUpTo100([8.33, 8.33, 8.33, 8.33, 8.33, 8.33, 8.33, 8.33, 8.34, 8.34, 8.34, 8.34])).toBe(true);
        expect(addsUpTo100(30, 30, 30)).toBe(false);
        expect(partValue(9000000, 50)).toBe(4500000);
    });
});
