/**
 * Keeps the public tracking page current.
 *
 * `start(onUpdate, onState)` calls `load()` on a schedule and hands each result to
 * `onUpdate`; `onState` hears `live`, `reconnecting` or `paused`. It polls every 20
 * seconds while the delivery is moving and every 60 otherwise, pauses while the tab is
 * hidden, and backs off up to 2 minutes when requests fail. A socket source can replace
 * it later with the same two callbacks.
 */
export const ACTIVE_INTERVAL = 20000;
export const IDLE_INTERVAL = 60000;
export const MAX_BACKOFF = 120000;

export default class TrackingLiveSource {
    constructor(load, { isActive = () => true, documentRef = typeof document === 'undefined' ? null : document } = {}) {
        this.load = load;
        this.isActive = isActive;
        this.document = documentRef;
        this.timer = null;
        this.failures = 0;
        this.running = false;
        this.onVisibility = () => this.visibilityChanged();
    }

    start(onUpdate, onState = () => {}) {
        this.onUpdate = onUpdate;
        this.onState = onState;
        this.running = true;
        this.document?.addEventListener('visibilitychange', this.onVisibility);
        this.schedule();
    }

    stop() {
        this.running = false;
        clearTimeout(this.timer);
        this.timer = null;
        this.document?.removeEventListener('visibilitychange', this.onVisibility);
    }

    get hidden() {
        return this.document?.visibilityState === 'hidden';
    }

    get delay() {
        if (this.failures > 0) {
            return Math.min(MAX_BACKOFF, ACTIVE_INTERVAL * Math.pow(2, this.failures - 1));
        }

        return this.isActive() ? ACTIVE_INTERVAL : IDLE_INTERVAL;
    }

    schedule() {
        clearTimeout(this.timer);
        if (!this.running || this.hidden) {
            return;
        }

        this.timer = setTimeout(() => this.tick(), this.delay);
    }

    async tick() {
        if (!this.running) {
            return;
        }

        try {
            const result = await this.load();
            this.failures = 0;
            this.onState('live');
            this.onUpdate(result);
        } catch {
            this.failures += 1;
            this.onState('reconnecting');
        }

        this.schedule();
    }

    visibilityChanged() {
        if (this.hidden) {
            clearTimeout(this.timer);
            this.onState?.('paused');
            return;
        }

        this.tick();
    }
}

/**
 * An encoded polyline (precision 5, as Google Routes and OSRM return it) as [lat, lng] pairs.
 */
export function decodePolyline(encoded) {
    if (typeof encoded !== 'string' || encoded === '') {
        return [];
    }

    const points = [];
    let index = 0;
    let lat = 0;
    let lng = 0;

    while (index < encoded.length) {
        const deltas = [];
        for (let axis = 0; axis < 2; axis++) {
            let result = 0;
            let shift = 0;
            let byte;
            do {
                byte = encoded.charCodeAt(index++) - 63;
                result |= (byte & 0x1f) << shift;
                shift += 5;
            } while (byte >= 0x20 && index < encoded.length);
            deltas.push(result & 1 ? ~(result >> 1) : result >> 1);
        }

        lat += deltas[0];
        lng += deltas[1];
        points.push([lat / 1e5, lng / 1e5]);
    }

    return points;
}
