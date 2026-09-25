/**
 * Calendar-day helpers for report periods. All functions work on *local* calendar days
 * (what the user sees in the picker) and serialise to plain `YYYY-MM-DD` strings — never
 * `toISOString()`, which would shift the day for anyone east of UTC.
 */

const DAY_MS = 86_400_000;

export const startOfDay = date => new Date(date.getFullYear(), date.getMonth(), date.getDate());

export const addDays = (date, amount) => new Date(date.getFullYear(), date.getMonth(), date.getDate() + amount);

export const isSameDay = (a, b) =>
    !!a && !!b && a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();

export const isSameRange = (a, b) => !!a && !!b && isSameDay(a.from, b.from) && isSameDay(a.to, b.to);

/** Inclusive number of calendar days between two dates (same day → 1). */
export const daysInRange = (from, to) => Math.round((startOfDay(to) - startOfDay(from)) / DAY_MS) + 1;

export const toISODate = date =>
    `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;

export const parseISODate = value => {
    const [y, m, d] = String(value).split('-').map(Number);
    return new Date(y, m - 1, d);
};

/** WordPress locales are `en_US`; Intl wants `en-US`. */
export const toBcp47 = locale => (locale || '').replace(/_/g, '-') || undefined;

export const formatDate = (date, locale, options = { month: 'short', day: 'numeric', year: 'numeric' }) =>
    new Intl.DateTimeFormat(toBcp47(locale), options).format(date);

export const formatDateRange = (from, to, locale, options = { month: 'short', day: 'numeric', year: 'numeric' }) => {
    const formatter = new Intl.DateTimeFormat(toBcp47(locale), options);
    if (isSameDay(from, to)) return formatter.format(from);
    return typeof formatter.formatRange === 'function'
        ? formatter.formatRange(from, to)
        : `${formatter.format(from)} – ${formatter.format(to)}`;
};

/** `{ from, to }` covering the last `count` days including today. */
export const lastDays = (count, today = new Date()) => {
    const to = startOfDay(today);
    return { from: addDays(to, -(count - 1)), to };
};

export const thisMonth = (today = new Date()) => {
    const to = startOfDay(today);
    return { from: new Date(to.getFullYear(), to.getMonth(), 1), to };
};

export const lastMonth = (today = new Date()) => {
    const first = new Date(today.getFullYear(), today.getMonth() - 1, 1);
    return { from: first, to: new Date(today.getFullYear(), today.getMonth(), 0) };
};

/**
 * "3 minutes ago" for a UTC `YYYY-MM-DD HH:MM:SS` timestamp, as the email logs store them, or
 * for a full ISO 8601 string. Returns an empty string for a value that does not parse.
 *
 * @since 1.0.0
 *
 * @param {string} value    UTC timestamp from the API.
 * @param {string} [locale] WordPress locale (`en_US`).
 * @param {Date}   [now]    Reference time; the current time by default.
 * @returns {string}
 */
export const formatRelativeTime = (value, locale, now = new Date()) => {
    const raw = String(value || '');
    // The API's `Y-m-d H:i:s` carries no zone and is UTC; a full ISO string is read as given.
    const time = Date.parse(/[zZ]|[+-]\d{2}:?\d{2}$/.test(raw) ? raw : `${raw.replace(' ', 'T')}Z`);
    if (Number.isNaN(time)) return '';
    const seconds = Math.round((time - now.getTime()) / 1000);
    const units = [['year', 31_536_000], ['month', 2_592_000], ['week', 604_800], ['day', 86_400], ['hour', 3_600], ['minute', 60]];
    const formatter = new Intl.RelativeTimeFormat(toBcp47(locale), { numeric: 'auto' });
    for (const [unit, size] of units) {
        if (Math.abs(seconds) >= size) return formatter.format(Math.trunc(seconds / size), unit);
    }
    return formatter.format(seconds, 'second');
};
