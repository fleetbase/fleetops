import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { getOwner } from '@ember/application';
import { debug } from '@ember/debug';
import { task } from 'ember-concurrency';
import { renderCompleted, waitForInsertedAndSized } from '@fleetbase/ember-ui/utils/dom';
import LeafletTrackingMarkerComponent from '../leaflet-tracking-marker';

const DEFAULT_CENTER = [1.31, 103.85];
const DEFAULT_ZOOM = 11;
// Match the rest of fleetops' Leaflet maps (light Carto basemap), which keeps the
// dashboard styling consistent with the operational live map. Falls back gracefully
// in dark mode via the next-leaflet-container-map theming.
const TILE_URL = 'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}.png';
const TILE_URL_DARK = 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}.png';
const TILE_ATTRIBUTION = '&copy; OpenStreetMap contributors &copy; CARTO';
const RECONCILE_INTERVAL_MS = 5 * 60_000;

/**
 * Real-time fleet map. Initial GET seeds driver positions; the widget then
 * subscribes to `company.{uuid}` and applies driver.location_changed deltas
 * directly to the markers list. Full reconciliation every 5 minutes.
 */
export default class WidgetLiveFleetComponent extends Component {
    static widgetId = 'fleet-ops-live-fleet-widget';

    @service fetch;
    @service socket;
    @service currentUser;

    @tracked data = null;
    @tracked error = null;

    tileAttribution = TILE_ATTRIBUTION;

    get tileUrl() {
        const isDark = typeof document !== 'undefined' && document.documentElement?.dataset?.theme === 'dark';
        return isDark ? TILE_URL_DARK : TILE_URL;
    }

    socketChannel = null;
    socketActive = false;
    reconcileTimer = null;
    /** Watches the map container for size changes (gridstack settle, sibling widgets
     *  loading, theme/font swaps) and re-runs Leaflet's invalidateSize so the tile
     *  layer always fills the actual painted bounds, not the bounds Leaflet measured
     *  at first paint. */
    #resizeObserver = null;
    #resizeRaf = 0;

    /**
     * Deferred that resolves once <LeafletMap @onLoad> fires. Mirrors the
     * leaflet-map-manager service's #mapSetPromise so we can synchronously
     * wait for the map instance from anywhere in this component.
     */
    #map = null;
    #mapSetPromise = null;
    #resolveMapSet = null;

    constructor() {
        super(...arguments);
        // Important: register tracking-marker against THIS owner's ember-leaflet
        // service before any <LeafletMap> in our template is constructed.
        //
        // The fleetops `register-leaflet-tracking-marker` instance-initializer
        // registers `tracking-marker` on the fleetops ENGINE's ember-leaflet
        // service. When this widget is rendered from the host dashboard route
        // (e.g. console.home), LazyEngineComponent resolves the component class
        // from the engine but renders it under the HOST owner — so the
        // <LeafletMap> inside this template injects the host's ember-leaflet
        // service, which never received the registration. The yield for
        // `layers.tracking-marker` silently resolves to undefined and no marker
        // ever paints. Doing the registration on whatever owner this widget
        // happens to live under closes that gap.
        this.#ensureTrackingMarkerRegistered();
        this.#patchLeafletDrawInitHook();
        this.#resetMapDeferred();
        this.load.perform();
        this.subscribe();
        this.reconcileTimer = setInterval(() => this.load.perform(), RECONCILE_INTERVAL_MS);
    }

    /**
     * leaflet-draw's `Edit.Marker.js` registers an init hook on `L.Marker` that
     * does `if (L.Edit.Marker) { this.editing = new L.Edit.Marker(this); … }`.
     * The check is unguarded — it reads `.Marker` off `L.Edit` directly. When
     * leaflet-draw is loaded but the dashboard route's window.L doesn't carry
     * the `Edit` namespace (load-order race in the host route, or two leaflet
     * copies in the runtime caused by engine/host module duplication), every
     * marker creation throws a TypeError and our tracking-marker churn during
     * dashboard switching repeats it thousands of times.
     *
     * Stubbing `L.Edit` to `{}` keeps the leaflet-draw hook's check (`if
     * (L.Edit.Marker)`) safely falsy when the namespace is missing, so it
     * becomes a no-op. If leaflet-draw HAS fully loaded, `L.Edit.Marker` is
     * already set and the `||` keeps it.
     *
     * We don't need draw/editing handlers on dashboard markers anyway — this
     * widget is read-only.
     */
    #patchLeafletDrawInitHook() {
        const L = typeof window !== 'undefined' ? window.L ?? window.leaflet : null;
        if (L && !L.Edit) {
            L.Edit = {};
        }
        if (L && !L.Marker) {
            L.Marker = {};
        }
    }

    /**
     * Idempotent: registers the fleetops `tracking-marker` component on the
     * `ember-leaflet` service belonging to this widget's owner if it isn't
     * already there. Safe to call multiple times across widget instances
     * because we guard on `components.find(...)` before calling
     * `registerComponent` (which throws on duplicate registration).
     */
    #ensureTrackingMarkerRegistered() {
        const owner = getOwner(this);
        const emberLeaflet = owner?.lookup?.('service:ember-leaflet');
        if (!emberLeaflet) return;

        const already = emberLeaflet.components?.find(
            (c) => c.name === 'leaflet-tracking-marker' || c.as === 'tracking-marker',
        );
        if (already) return;

        emberLeaflet.registerComponent('leaflet-tracking-marker', {
            as: 'tracking-marker',
            component: LeafletTrackingMarkerComponent,
        });
    }

    willDestroy() {
        super.willDestroy(...arguments);
        this.unsubscribe();
        if (this.reconcileTimer) clearInterval(this.reconcileTimer);
        if (this.#resizeRaf) cancelAnimationFrame(this.#resizeRaf);
        this.#resizeObserver?.disconnect();
        this.#resizeObserver = null;
    }

    get drivers() {
        return this.data?.drivers ?? [];
    }

    get vehicles() {
        return this.data?.vehicles ?? [];
    }

    get activeOrderCount() {
        return this.data?.active_orders?.length ?? 0;
    }

    /** Both drivers and vehicles are renderable map subjects — total chip count. */
    get totalMarkerCount() {
        return this.drivers.length + this.vehicles.length;
    }

    /** Center on the first available marker (driver, then vehicle), else default. */
    get center() {
        const first = this.drivers[0] ?? this.vehicles[0];
        if (first?.lat && first?.lng) return [first.lat, first.lng];
        return DEFAULT_CENTER;
    }

    get zoom() {
        return DEFAULT_ZOOM;
    }

    statusColor(status) {
        switch (status) {
            case 'in_progress':
            case 'enroute':
            case 'dispatched':
                return '#3485e2';
            case 'completed':
                return '#22c55e';
            default:
                return '#9ca3af';
        }
    }

    @task *load() {
        try {
            // Wait for the LeafletMap to mount, finish loading, and have a non-zero
            // container before we add markers. Without this, markers added to a
            // not-yet-sized map silently no-op (the data arrives, count chip ticks
            // up, but nothing paints).
            yield this.ensureInteractive();
            this.data = yield this.fetch.get('fleet-ops/analytics/live-fleet');
            this.error = null;
        } catch (e) {
            this.error = e?.message ?? 'Failed to load live fleet';
        }
    }

    /**
     * Mirrors LeafletMapManagerService#ensureInteractive: waits for the
     * Leaflet instance to be available + loaded, for the host DOM container
     * to be inserted and sized, then forces a re-measure. Returns the map.
     */
    async ensureInteractive({ timeoutMs = 8000 } = {}) {
        const map = await this.#whenMapLoaded({ timeoutMs });

        const getContainer = () => map?.getContainer?.() || map?._container || null;

        await renderCompleted();
        await waitForInsertedAndSized(getContainer, { timeoutMs });

        map.invalidateSize(false);

        // Subsequent size changes (gridstack settling, sibling widgets loading,
        // theme/font swaps, widget resize handles) need invalidateSize() again
        // or the tile layer ends up clipped to the cached bounding box.
        this.#attachResizeObserver(map, getContainer());

        return map;
    }

    /**
     * Watch the Leaflet container with a ResizeObserver and re-run invalidateSize
     * on any dimension change. rAF-debounced so a stream of resize callbacks during
     * a gridstack drag/animation collapses into one Leaflet recompute per frame.
     */
    #attachResizeObserver(map, container) {
        if (!container || typeof ResizeObserver === 'undefined') return;
        this.#resizeObserver?.disconnect();

        this.#resizeObserver = new ResizeObserver(() => {
            if (this.#resizeRaf) cancelAnimationFrame(this.#resizeRaf);
            this.#resizeRaf = requestAnimationFrame(() => {
                this.#resizeRaf = 0;
                try {
                    map.invalidateSize(false);
                } catch (e) {
                    debug(`[live-fleet] invalidateSize failed: ${e?.message ?? e}`);
                }
            });
        });
        this.#resizeObserver.observe(container);
    }

    /** Hook fired by <LeafletMap @onLoad>. Stores the instance + resolves the deferred. */
    @action onMapLoad({ target: map }) {
        this.#map = map;
        this.#resolveMapSet?.(map);
    }

    /** ──────────────────────────────────────────────
     *  Internal map-readiness deferred (same shape as
     *  leaflet-map-manager.js).
     *  ────────────────────────────────────────────── */

    #resetMapDeferred() {
        this.#mapSetPromise = new Promise((resolve) => {
            this.#resolveMapSet = resolve;
        });
    }

    async #waitForMap({ timeoutMs = 8000 } = {}) {
        if (this.#map) return this.#map;

        let to;
        try {
            return await Promise.race([
                this.#mapSetPromise,
                new Promise((_, rej) => {
                    if (timeoutMs != null) {
                        to = setTimeout(() => rej(new Error('waitForMap timed out')), timeoutMs);
                    }
                }),
            ]);
        } finally {
            if (to) clearTimeout(to);
        }
    }

    async #whenMapLoaded({ timeoutMs } = {}) {
        const map = await this.#waitForMap({ timeoutMs });
        if (map._loaded) return map;

        return new Promise((resolve, reject) => {
            let t;
            const onLoad = () => {
                clearTimeout(t);
                map.off('load', onLoad);
                resolve(map);
            };
            map.on('load', onLoad);
            if (timeoutMs != null) {
                t = setTimeout(() => {
                    map.off('load', onLoad);
                    reject(new Error('whenMapLoaded timed out'));
                }, timeoutMs);
            }
        });
    }

    @action
    refresh() {
        this.load.perform();
    }

    async subscribe() {
        const companyId = this.currentUser?.companyId;
        if (!companyId) return;

        try {
            const sc = this.socket.instance();
            const channel = sc.subscribe(`company.${companyId}`);
            this.socketChannel = channel;
            this.socketActive = true;

            if (channel.state !== 'subscribed') {
                await channel.subscribe();
            }

            for await (const msg of channel) {
                if (!this.socketActive) break;
                this.applyEvent(msg);
            }
        } catch (e) {
            debug(`[live-fleet] socket error: ${e?.message ?? e}`);
        }
    }

    async unsubscribe() {
        this.socketActive = false;
        try {
            await this.socketChannel?.unsubscribe();
        } catch (e) {
            debug(`[live-fleet] unsubscribe error: ${e?.message ?? e}`);
        }
        this.socketChannel = null;
    }

    applyEvent(msg) {
        if (!this.data || !msg) return;

        switch (msg.event) {
            case 'driver.location_changed':
                this.applyLocationDelta(msg.data);
                break;
            case 'driver.online':
            case 'driver.offline':
                // Presence flips are reconciled on the next 5-min refetch.
                break;
            default:
                return;
        }
    }

    applyLocationDelta(data) {
        if (!data?.uuid && !data?.driver_uuid) return;
        const targetUuid = data.uuid ?? data.driver_uuid;
        const lat = data.lat ?? data.latitude;
        const lng = data.lng ?? data.longitude;
        if (typeof lat !== 'number' || typeof lng !== 'number') return;

        const drivers = (this.data.drivers ?? []).map((d) =>
            d.uuid === targetUuid ? { ...d, lat, lng, heading: data.heading ?? d.heading } : d,
        );

        this.data = { ...this.data, drivers };
    }
}
