// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — Study dates (for the study form)
//  Location: resources/js/Utils/studyDates.js
//
//  Months are written "YYYY-MM". The server (App\Support\StudyCalendar) is the authority; these small
//  helpers only show the person the end date while they type, using the very same rule:
//    last month = start + N years - 1 month;  the study ENDS in December of that year.
//
//    studyEndYear('2026-03', 5)   → 2031     (March 2026 + 5 years - 1 month = February 2031)
//    studyEnd('2026-01', 5)       → '2030-12'
// ══════════════════════════════════════════════════════════════════

const MONTH = /^(\d{4})-(\d{2})$/;

export function parseMonth(value) {
    const found = MONTH.exec(String(value ?? ''));

    return found ? { year: Number(found[1]), month: Number(found[2]) } : null;
}

export function formatMonth(year, month) {
    return `${String(year).padStart(4, '0')}-${String(month).padStart(2, '0')}`;
}

/** The year of the last month of the study: start + N years - 1 month. */
export function studyEndYear(start, years) {
    const from = parseMonth(start);
    const n = Number(years);

    if (!from || !Number.isInteger(n) || n < 1) {
        return null;
    }

    return Math.floor((from.year * 12 + (from.month - 1) + n * 12 - 1) / 12);
}

/** The study end month ("YYYY-12"), or null while the start or the duration is missing. */
export function studyEnd(start, years) {
    const year = studyEndYear(start, years);

    return year === null ? null : formatMonth(year, 12);
}

/** True when month a is before month b ("YYYY-MM" strings compare correctly as text). */
export function isBefore(a, b) {
    return Boolean(a && b) && a < b;
}

/** The years offered in a year drop-down: a window around today that always contains the current value. */
export function yearOptions(current, from = new Date().getFullYear() - 5, to = new Date().getFullYear() + 12) {
    const value = parseMonth(current)?.year;
    const low = value ? Math.min(from, value) : from;
    const high = value ? Math.max(to, value) : to;

    return Array.from({ length: high - low + 1 }, (_, i) => low + i);
}

/** How many months from month a to month b (negative when b is earlier), or null when either is not a "YYYY-MM". */
export function monthsBetween(a, b) {
    const from = parseMonth(a);
    const to = parseMonth(b);

    return from && to ? (to.year * 12 + to.month) - (from.year * 12 + from.month) : null;
}

/** The month n months after (or before, when negative) a "YYYY-MM"; null when the month is not valid. */
export function addMonths(value, n) {
    const from = parseMonth(value);

    if (!from) {
        return null;
    }
    const total = from.year * 12 + (from.month - 1) + Number(n);

    return formatMonth(Math.floor(total / 12), (total % 12) + 1);
}
