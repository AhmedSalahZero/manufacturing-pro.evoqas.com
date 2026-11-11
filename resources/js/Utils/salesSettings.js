// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — Sales channels and export (study form helpers)
//  Location: resources/js/Utils/salesSettings.js
//
//  Small pure helpers for the "Sales channels and export" card on the study form.
//  The server decides everything (App\Http\Requests\UpdateProjectRequest); these only help the person while typing.
//    studyYears('2026-01', 3)             → [2026, 2027, 2028]       (one FX rate per year; year index 0 = the first)
//    foreignCurrencies(all, 'egp')        → every currency except the study currency (R1.2)
//    syncFxRows(rows, ['usd'], 3)         → one row per chosen currency, each with a rate slot for every year, typed rates kept
//    duplicateNames(['Retail','retail'])  → indexes of the names that repeat (case ignored)
// ══════════════════════════════════════════════════════════════════
import { parseMonth, studyEndYear } from '@/Utils/studyDates';

/** The calendar years of the study, from the start year to the year of the last study month; [] while the start or duration is missing. */
export function studyYears(start, duration) {
    const from = parseMonth(start);
    const last = studyEndYear(start, duration);

    if (!from || last === null || last < from.year) {
        return [];
    }

    return Array.from({ length: last - from.year + 1 }, (_, i) => from.year + i);
}

/** The currencies that can be foreign ones: everything except the study currency. */
export function foreignCurrencies(all, studyCurrency) {
    return (all ?? []).filter((code) => code !== studyCurrency);
}

/**
 * One FX row per chosen currency (in the order chosen), each with a rate slot for every study year.
 * What was typed is kept; a new year or currency starts empty.
 */
export function syncFxRows(rows, currencies, yearCount) {
    return currencies.map((currency) => {
        const old = (rows ?? []).find((row) => row.currency === currency);
        const rates = {};

        for (let year = 0; year < yearCount; year += 1) {
            rates[year] = old?.rates?.[year] ?? '';
        }

        return { currency, rates };
    });
}

/** Indexes of the names that appear more than once (case ignored, blanks skipped). */
export function duplicateNames(names) {
    const seen = {};

    names.forEach((name, index) => {
        const key = String(name ?? '').trim().toLowerCase();
        if (key) (seen[key] ??= []).push(index);
    });

    return Object.values(seen).filter((list) => list.length > 1).flat();
}

/** True when the list can still grow (the limits come from the server: 8 channels, 10 markets, 6 currencies). */
export function canAdd(list, max) {
    return list.length < max;
}
