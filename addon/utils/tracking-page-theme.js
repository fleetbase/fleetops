/**
 * Colour tokens for the customer tracking page, derived from a company's one accent colour.
 *
 * The server sanitizes and checks the same values (TrackingPageConfig); this mirrors it so
 * the settings preview and the page itself re-theme live without a round trip.
 */
export const DEFAULT_ACCENT = '#1F5FA8';
export const LIGHT_INK = '#FFFFFF';
export const DARK_INK = '#14191A';

const LIGHT_BASE = {
    bg: '#F3F4F1',
    surface: '#FFFFFF',
    surface2: '#ECEEEA',
    text: '#14191A',
    muted: '#545D5F',
    border: '#D9DED9',
    warn: '#A8430B',
    'warn-soft': '#FBEADF',
    neutral: '#5F686A',
};

const DARK_BASE = {
    bg: '#0D1112',
    surface: '#151A1B',
    surface2: '#1D2324',
    text: '#E9EDEA',
    muted: '#A1ABAB',
    border: '#2B3335',
    warn: '#F2A262',
    'warn-soft': '#3A2416',
    neutral: '#8E9899',
};

/**
 * `#RGB` or `#RRGGBB` as uppercase `#RRGGBB`, or null.
 */
export function normalizeHex(value) {
    const match = typeof value === 'string' ? value.trim().match(/^#?([0-9a-f]{3}|[0-9a-f]{6})$/i) : null;
    if (!match) {
        return null;
    }

    let hex = match[1];
    if (hex.length === 3) {
        hex = hex.replace(/./g, (char) => char + char);
    }

    return `#${hex.toUpperCase()}`;
}

function channels(hex) {
    const value = hex.replace('#', '');
    return [0, 2, 4].map((offset) => parseInt(value.slice(offset, offset + 2), 16));
}

function luminance(hex) {
    const [r, g, b] = channels(hex).map((channel) => {
        const value = channel / 255;
        return value <= 0.03928 ? value / 12.92 : Math.pow((value + 0.055) / 1.055, 2.4);
    });

    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

/**
 * The WCAG contrast ratio of two `#RRGGBB` colours, rounded to two decimals.
 */
export function contrastRatio(a, b) {
    const lighter = Math.max(luminance(a), luminance(b));
    const darker = Math.min(luminance(a), luminance(b));

    return Math.round(((lighter + 0.05) / (darker + 0.05)) * 100) / 100;
}

/**
 * The text colour readable on an accent: white when it reaches 4.5:1, otherwise dark.
 */
export function inkFor(accent) {
    return contrastRatio(accent, LIGHT_INK) >= 4.5 ? LIGHT_INK : DARK_INK;
}

/**
 * A lighter accent for dark mode: the accent mixed toward white until it reads on the dark surface.
 */
export function darkAccentFor(accent) {
    const [r, g, b] = channels(accent);
    for (let weight = 0.25; weight <= 0.85; weight += 0.05) {
        const mixed = [r, g, b].map((channel) => Math.round(channel + (255 - channel) * weight));
        const hex = `#${mixed.map((channel) => channel.toString(16).padStart(2, '0')).join('')}`.toUpperCase();
        if (contrastRatio(hex, DARK_BASE.surface) >= 4.5) {
            return hex;
        }
    }

    return '#FFFFFF';
}

/**
 * The CSS custom properties for a page in the given mode, as a style string.
 *
 * @param {Object}  branding the config's branding group
 * @param {Boolean} dark     whether the page renders dark
 */
export default function trackingPageTheme(branding = {}, dark = false) {
    const accent = normalizeHex(branding.accent) ?? DEFAULT_ACCENT;
    const darkAccent = normalizeHex(branding.accent_dark) ?? darkAccentFor(accent);
    const modeAccent = dark ? darkAccent : accent;
    const base = dark ? DARK_BASE : LIGHT_BASE;
    const tokens = {
        ...base,
        accent: modeAccent,
        ink: inkFor(modeAccent),
        'accent-soft': `color-mix(in oklab, ${modeAccent} 14%, ${base.surface})`,
    };

    const declarations = [`color-scheme: ${dark ? 'dark' : 'light'}`];
    for (const name of Object.keys(tokens)) {
        declarations.push(`--tp-${name}: ${tokens[name]}`);
    }

    return declarations.join('; ');
}
