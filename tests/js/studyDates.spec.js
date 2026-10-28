// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — Study dates tests (run: npm test)
//  Location: tests/js/studyDates.spec.js
//  The same cases as the server's StudyCalendar (tests/Unit/StudyCalendarTest.php), which are checked
//  against 36 saved projects of the previous ZAVERO.
// ══════════════════════════════════════════════════════════════════
import { describe, expect, it } from 'vitest';
import { addMonths, formatMonth, isBefore, monthsBetween, parseMonth, studyEnd, studyEndYear, yearOptions } from '@/Utils/studyDates';

describe('study end date', () => {
    it.each([
        ['2025-01', 7, '2031-12'],
        ['2025-01', 5, '2029-12'],
        ['2026-01', 5, '2030-12'],
        ['2025-01', 6, '2030-12'],
        ['2024-01', 7, '2030-12'],
        ['2025-01', 1, '2025-12'],
        ['2025-12', 4, '2029-12'],
        ['2025-07', 5, '2030-12'],
        ['2026-03', 5, '2031-12'],
    ])('a study starting %s for %i years ends %s', (start, years, end) => {
        expect(studyEnd(start, years)).toBe(end);
    });

    it('is the year of start + N years - 1 month', () => {
        expect(studyEndYear('2026-03', 5)).toBe(2031);
        expect(studyEndYear('2026-01', 5)).toBe(2030);
    });

    it('gives nothing while the start or the duration is missing', () => {
        expect(studyEnd('', 5)).toBeNull();
        expect(studyEnd('2026-01', '')).toBeNull();
        expect(studyEnd('2026-01', 0)).toBeNull();
        expect(studyEnd('nonsense', 3)).toBeNull();
    });
});

describe('months', () => {
    it('reads and writes YYYY-MM', () => {
        expect(parseMonth('2026-03')).toEqual({ year: 2026, month: 3 });
        expect(parseMonth('2026-3')).toBeNull();
        expect(formatMonth(2026, 3)).toBe('2026-03');
    });

    it('compares months', () => {
        expect(isBefore('2026-01', '2026-02')).toBe(true);
        expect(isBefore('2026-02', '2026-02')).toBe(false);
        expect(isBefore('', '2026-02')).toBe(false);
    });

    it('offers a window of years that always contains the current value', () => {
        const years = yearOptions('2010-05', 2020, 2030);
        expect(years[0]).toBe(2010);
        expect(years.at(-1)).toBe(2030);
        expect(yearOptions('', 2020, 2022)).toEqual([2020, 2021, 2022]);
    });
});

describe('moving the study start moves every start date with it', () => {
    it('counts months between two months', () => {
        expect(monthsBetween('2027-01', '2027-04')).toBe(3);
        expect(monthsBetween('2027-04', '2027-01')).toBe(-3);
        expect(monthsBetween('2026-11', '2027-02')).toBe(3);
        expect(monthsBetween('', '2027-02')).toBeNull();
    });

    it('adds months across a year end', () => {
        expect(addMonths('2027-04', 3)).toBe('2027-07');
        expect(addMonths('2027-11', 3)).toBe('2028-02');
        expect(addMonths('2027-02', -3)).toBe('2026-11');
        expect(addMonths('x', 3)).toBeNull();
    });

    it('keeps the position from the study start: operation April 2027 (3) follows a start moved to March 2027', () => {
        const shift = monthsBetween('2027-01', '2027-03');

        expect(addMonths('2027-04', shift)).toBe('2027-06');
    });
});
