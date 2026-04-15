import { helper } from '@ember/component/helper';

/**
 * Presentational helper for insurance-expiry coloring.
 *
 * Returns a Tailwind text-color class based on how soon a vendor's
 * insurance policy expires:
 *   - expired (past)       → 'text-danger'  (red)
 *   - within 30 days       → 'text-warning' (yellow)
 *   - more than 30 days    → 'text-success' (green)
 *   - null / missing / bad → ''             (neutral)
 *
 * Locked thresholds per Phase 2 Task 11 spec. Kept pure and synchronous —
 * no store lookups, no model mutations, no moment/date-fns dependency.
 */
export function insuranceExpiryClass([expiry]) {
    if (!expiry) {
        return '';
    }

    const timestamp = expiry instanceof Date ? expiry.getTime() : new Date(expiry).getTime();
    if (Number.isNaN(timestamp)) {
        return '';
    }

    const days = (timestamp - Date.now()) / 86400000;

    if (days < 0) {
        return 'text-danger';
    }

    if (days <= 30) {
        return 'text-warning';
    }

    return 'text-success';
}

export default helper(insuranceExpiryClass);
