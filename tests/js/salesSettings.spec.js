// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — tests of the "Sales channels and export" helpers (run: npm test)
//  Location: tests/js/salesSettings.spec.js
// ══════════════════════════════════════════════════════════════════
import { describe, expect, it } from 'vitest';
import { canAdd, duplicateNames, foreignCurrencies, studyYears, syncFxRows } from '@/Utils/salesSettings';

describe('studyYears', () => {
    it('lists the calendar years of the study', () => {
        expect(studyYears('2026-01', 3)).toEqual([2026, 2027, 2028]);
        expect(studyYears('2026-01', 5)).toEqual([2026, 2027, 2028, 2029, 2030]);
    });

    it('follows the same rule as the server when the study starts mid-year', () => {
        // March 2026 + 5 years - 1 month = February 2031, so the study covers 2026 to 2031.
        expect(studyYears('2026-03', 5)).toEqual([2026, 2027, 2028, 2029, 2030, 2031]);
    });

    it('is empty until the start and the duration are known', () => {
        expect(studyYears('', 3)).toEqual([]);
        expect(studyYears('2026-01', '')).toEqual([]);
    });
});

describe('foreignCurrencies', () => {
    it('never offers the study currency', () => {
        expect(foreignCurrencies(['egp', 'usd', 'euro'], 'egp')).toEqual(['usd', 'euro']);
        expect(foreignCurrencies(['egp', 'usd', 'euro'], 'usd')).toEqual(['egp', 'euro']);
    });
});

describe('syncFxRows', () => {
    it('gives every chosen currency a slot for every year and keeps what was typed', () => {
        const rows = [{ currency: 'usd', rates: { 0: '50', 1: '54' } }];

        expect(syncFxRows(rows, ['usd', 'euro'], 3)).toEqual([
            { currency: 'usd', rates: { 0: '50', 1: '54', 2: '' } },
            { currency: 'euro', rates: { 0: '', 1: '', 2: '' } },
        ]);
    });

    it('drops a currency that is no longer chosen and a year that is no longer in the study', () => {
        const rows = [{ currency: 'usd', rates: { 0: '50', 1: '54', 2: '58' } }, { currency: 'euro', rates: { 0: '55' } }];

        expect(syncFxRows(rows, ['usd'], 2)).toEqual([{ currency: 'usd', rates: { 0: '50', 1: '54' } }]);
    });
});

describe('duplicateNames', () => {
    it('finds repeated names whatever the capital letters', () => {
        expect(duplicateNames(['Retail', 'Wholesale', 'retail ', ''])).toEqual([0, 2]);
        expect(duplicateNames(['A', 'B'])).toEqual([]);
        expect(duplicateNames(['', ''])).toEqual([]);
    });
});

describe('canAdd', () => {
    it('stops at the limit', () => {
        expect(canAdd([1, 2, 3], 4)).toBe(true);
        expect(canAdd([1, 2, 3, 4], 4)).toBe(false);
    });
});
