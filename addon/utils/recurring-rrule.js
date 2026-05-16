export const WEEKDAY_OPTIONS = [
    { code: 'MO', label: 'Mon' },
    { code: 'TU', label: 'Tue' },
    { code: 'WE', label: 'Wed' },
    { code: 'TH', label: 'Thu' },
    { code: 'FR', label: 'Fri' },
    { code: 'SA', label: 'Sat' },
    { code: 'SU', label: 'Sun' },
];

export function parseRrule(rrule = '') {
    const normalized = String(rrule).replace(/^RRULE:/i, '');
    const parts = Object.fromEntries(
        normalized
            .split(';')
            .map((segment) => segment.trim())
            .filter(Boolean)
            .map((segment) => {
                const [key, value] = segment.split('=');
                return [key?.toUpperCase(), value];
            })
    );

    return {
        frequency: String(parts.FREQ ?? 'WEEKLY').toLowerCase(),
        interval: Number(parts.INTERVAL ?? 1),
        weekdays: String(parts.BYDAY ?? '')
            .split(',')
            .map((value) => value.trim())
            .filter(Boolean),
        monthday: parts.BYMONTHDAY ? Number(parts.BYMONTHDAY) : null,
        until: parts.UNTIL ?? null,
    };
}

export function buildRrule({ frequency = 'weekly', interval = 1, weekdays = [], monthday = null, until = null } = {}) {
    const normalizedFrequency = String(frequency || 'weekly').toUpperCase();
    const normalizedInterval = Math.max(1, Number(interval) || 1);
    const parts = [`FREQ=${normalizedFrequency}`, `INTERVAL=${normalizedInterval}`];

    if (normalizedFrequency === 'WEEKLY' && weekdays.length > 0) {
        parts.push(`BYDAY=${weekdays.join(',')}`);
    }

    if (normalizedFrequency === 'MONTHLY' && monthday) {
        parts.push(`BYMONTHDAY=${monthday}`);
    }

    if (until) {
        const untilDate = until instanceof Date ? until : new Date(until);
        if (!Number.isNaN(untilDate.getTime())) {
            parts.push(`UNTIL=${untilDate.toISOString().replace(/[-:]/g, '').split('.')[0]}Z`);
        }
    }

    return parts.join(';');
}
