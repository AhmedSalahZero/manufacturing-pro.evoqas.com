// ZAVERO Manufacturing Pro — number and date formatting tests (run: npm test)
// Location: tests/js/format.spec.js
import { describe, expect, it } from 'vitest';
import { formatDate, formatNumber, formatPercent, setTimezone } from '@/Utils/format';

describe('formatNumber', () => {
    it('adds thousands separators and rounds', () => {
        expect(formatNumber(1234567.891)).toBe('1,234,568');
        expect(formatNumber(1234.5, 2)).toBe('1,234.50');
        expect(formatNumber(0)).toBe('0');
    });

    it('always uses Western digits and a plain minus sign', () => {
        expect(formatNumber(-1250)).toBe('-1,250');
        expect(/^[0-9,.\-]+$/.test(formatNumber(-9876543.21, 2))).toBe(true);
    });

    it('never shows "-0"', () => {
        expect(formatNumber(-0.0001, 2)).toBe('0.00');
        expect(formatNumber(-0.4)).toBe('0');
    });

    it('gives an empty text for no value', () => {
        expect(formatNumber(null)).toBe('');
        expect(formatNumber(undefined)).toBe('');
        expect(formatNumber('')).toBe('');
        expect(formatNumber('abc')).toBe('');
    });
});

describe('formatPercent', () => {
    it('turns a fraction into a percentage', () => {
        expect(formatPercent(0.1234)).toBe('12.3%');
        expect(formatPercent(0.5, 0)).toBe('50%');
        expect(formatPercent(null)).toBe('');
    });
});

describe('formatDate', () => {
    it('shows a plain date the same in every time zone', () => {
        setTimezone('Pacific/Kiritimati');
        expect(formatDate('2026-03-05')).toBe('5 Mar 2026');
        setTimezone('Africa/Cairo');
        expect(formatDate('2026-03-05')).toBe('5 Mar 2026');
    });

    it('keeps Western digits in Arabic', () => {
        expect(/[0-9]/.test(formatDate('2026-03-05', 'ar'))).toBe(true);
        expect(/[\u0660-\u0669]/.test(formatDate('2026-03-05', 'ar'))).toBe(false);
    });

    it('gives an empty text for nothing or nonsense', () => {
        expect(formatDate('')).toBe('');
        expect(formatDate('not a date')).toBe('');
    });
});
