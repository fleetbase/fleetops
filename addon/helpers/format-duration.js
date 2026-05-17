import { helper } from '@ember/component/helper';

export function formatDurationValue(seconds) {
    const value = Number(seconds);

    if (!Number.isFinite(value)) {
        return '0s';
    }

    const totalSeconds = Math.max(0, Math.ceil(value));
    const days = Math.floor(totalSeconds / 86400);
    const hours = Math.floor((totalSeconds % 86400) / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const remainingSeconds = totalSeconds % 60;
    const parts = [];

    if (days) {
        parts.push(`${days}d`);
    }

    if (hours) {
        parts.push(`${hours}h`);
    }

    if (!days && minutes) {
        parts.push(`${minutes}m`);
    }

    if (!days && !hours && parts.length < 2 && remainingSeconds) {
        parts.push(`${remainingSeconds}s`);
    }

    return parts.length ? parts.join(' ') : '0s';
}

export default helper(function formatDuration([seconds]) {
    return formatDurationValue(seconds);
});
