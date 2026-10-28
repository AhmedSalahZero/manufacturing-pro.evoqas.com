// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — Number and date formatting
//  Location: resources/js/Utils/format.js
//
//  Numbers ALWAYS use Western digits (0-9) with thousands separators, in English
//  and in Arabic, so a figure reads the same in both languages and in Excel.
//  Dates use the application's time zone (config/app.php), whatever the browser says.
//
//    formatNumber(1234567.891)      → "1,234,568"
//    formatNumber(1234.5, 2)        → "1,234.50"
//    formatNumber(-1250)            → "-1,250"        (always a plain minus sign)
//    formatPercent(0.1234)          → "12.3%"
//    formatDate('2026-03-05')       → "5 Mar 2026"   (English)   "٥ مارس ٢٠٢٦" is NOT used: digits stay 0-9
// ══════════════════════════════════════════════════════════════════

let timezone = 'Africa/Cairo';

export function setTimezone(value) {
    if (typeof value === 'string' && value !== '') {
        timezone = value;
    }
}

export function getTimezone() {
    return timezone;
}

export function formatNumber(value, decimals = 0) {
    if (value === null || value === undefined || value === '' || Number.isNaN(Number(value))) {
        return '';
    }

    const text = new Intl.NumberFormat('en-US', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    }).format(Number(value));

    // Intl prints "-0" for tiny negatives that round to zero; show a plain 0.
    return /^-0(\.0+)?$/.test(text) ? text.slice(1) : text;
}

/** 0.1234 → "12.3%"  (the value is a fraction, not already a percentage) */
export function formatPercent(fraction, decimals = 1) {
    if (fraction === null || fraction === undefined || fraction === '' || Number.isNaN(Number(fraction))) {
        return '';
    }

    return `${formatNumber(Number(fraction) * 100, decimals)}%`;
}

export function formatDate(value, locale = 'en') {
    if (!value) {
        return '';
    }

    const date = typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value) ? new Date(`${value}T12:00:00Z`) : new Date(value);
    if (Number.isNaN(date.getTime())) {
        return '';
    }

    // "-u-nu-latn" forces Western digits even in Arabic.
    return new Intl.DateTimeFormat(locale === 'ar' ? 'ar-EG-u-nu-latn' : 'en-GB', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        timeZone: typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value) ? 'UTC' : timezone,
    }).format(date);
}
