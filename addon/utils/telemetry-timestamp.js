/** Source timestamps without an offset are UTC, matching the telemetry API. */
export default function telemetryTimestamp(value) {
    if (typeof value !== 'string' || !value.trim()) return NaN;
    const timestamp = value.trim();
    return Date.parse(/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(\.\d+)?$/.test(timestamp) ? `${timestamp.replace(' ', 'T')}Z` : timestamp);
}
