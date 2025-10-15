import Service from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action, set } from '@ember/object';
import { isArray } from '@ember/array';
import { renderCompleted, waitForInsertedAndSized } from '@fleetbase/ember-ui/utils/dom';
import { Control as RoutingControl } from '@fleetbase/leaflet-routing-machine';
import { getLayerById, findLayer, flyToLayer } from '../utils/leaflet';
import isUuid from '@fleetbase/ember-core/utils/is-uuid';

export default class LeafletMapManagerService extends Service {
    @service leafletRoutingControl;
    @service notifications;
    @tracked map;
    @tracked _livemap;
    @tracked route;
    @tracked routingControl;
    @tracked drawControl;
    @tracked drawControlFeatureGroup;
    @tracked leafletLayers = [];
    @tracked editableLayers = [];
    #mapSetPromise = null;
    #resolveMapSet = null;

    constructor() {
        super(...arguments);
        this.#resetMapDeferred();
    }

    /** map initialization methods */
    #resetMapDeferred() {
        this.#mapSetPromise = new Promise((resolve) => {
            this.#resolveMapSet = resolve;
        });
    }

    async waitForMap({ timeoutMs = 8000 } = {}) {
        // If already set, return immediately
        if (this.map) return this.map;

        let to;
        const p = Promise.race([
            this.#mapSetPromise,
            new Promise((_, rej) => {
                if (timeoutMs != null) {
                    to = setTimeout(() => rej(new Error('waitForMap timed out')), timeoutMs);
                }
            }),
        ]);

        try {
            return await p;
        } finally {
            if (to) clearTimeout(to);
        }
    }

    setMap(map) {
        this.map = map;
        this._livemap = map.livemap;

        if (map) {
            this.#resolveMapSet?.(map);
        } else {
            this.#resetMapDeferred();
        }
        return map;
    }

    whenMapLoaded({ timeoutMs } = {}) {
        return (async () => {
            const map = await this.waitForMap({ timeoutMs });
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
        })();
    }

    async ensureInteractive({ timeoutMs = 8000 } = {}) {
        await this.whenMapLoaded({ timeoutMs });

        // always ask Leaflet for the live container
        const getContainer = () => this.map?.getContainer?.() || this.map?._container || null;

        // wait until inserted + non-zero size
        await renderCompleted();
        await waitForInsertedAndSized(getContainer, { timeoutMs });

        // tell Leaflet to re-measure, then give it one paint
        this.map.invalidateSize(false);
        return this.map;
    }

    /** callback to live tracking map */
    livemap(fn, ...rest) {
        if (typeof fn === 'function' && this.map.livemap) {
            return fn(this.map.livemap);
        }
        if (this.map.livemap && typeof fn === 'string' && typeof this.map.livemap[fn] === 'function') {
            return this.map.livemap[fn](...rest);
        }

        return null;
    }

    /** layer helpers */
    getLayerById(layerId) {
        return getLayerById(this.map, layerId);
    }

    findLayer(findCallback) {
        return findLayer(this.map, findCallback);
    }

    flyToLayer(layer, zoom, options = {}) {
        return flyToLayer(this.map, layer, zoom, options);
    }

    getLayerByRecord(record) {
        const id = isUuid(record) ? record : record?.id;
        if (!id) return;
        return this.findLayer((l) => l.record_id === id);
    }

    flyToRecordLayer(record, zoom, options = {}) {
        const layer = this.getLayerByRecord(record);
        if (layer) {
            this.flyToLayer(layer, zoom, options);
        }
    }

    setDrawControl(control) {
        this.drawControl = control;
        return control;
    }

    setDrawControlFeatureGroup(featureGroup) {
        this.drawControlFeatureGroup = featureGroup;
        return featureGroup;
    }

    /** actions */
    @action hideLayer(layer) {
        set(layer, 'isVisible', false);
    }

    @action showLayer(layer) {
        set(layer, 'isVisible', true);
    }

    @action toggleDrawControl() {
        const ctl = this.drawControl;
        if (!this.map || !ctl) return;

        const isOn = !!ctl._map;
        if (isOn) {
            this.map.removeControl(ctl);
        } else {
            ctl.addTo(this.map);
        }
    }

    @action showDrawControl() {
        const ctl = this.drawControl;
        if (!this.map || !ctl) return;

        const isOn = !!ctl._map;
        if (!isOn) {
            ctl.addTo(this.map);
        }
    }

    @action hideDrawControl() {
        const ctl = this.drawControl;
        if (!this.map || !ctl) return;

        const isOn = !!ctl._map;
        if (isOn) {
            this.map.removeControl(ctl);
        }
    }

    @action zoom(direction = 'in') {
        if (direction === 'in') {
            this.map?.zoomIn();
        } else {
            this.map?.zoomOut();
        }
    }

    @action zoomIn() {
        this.map?.zoomIn();
    }

    @action zoomOut() {
        this.map?.zoomOut();
    }

    @action showCoordinates(event) {
        const wrappedLatLng = event.latlng.wrap();
        this.notifications.info(wrappedLatLng);
    }

    @action centerMap(event) {
        this.map.panTo(event.latlng);
    }

    /** routing methods */
    /* eslint-disable no-empty */
    async addRoutingControl(waypoints, options = {}) {
        if (!isArray(waypoints) || waypoints.length === 0) return;

        const map = await this.ensureInteractive({ timeoutMs: 8000 });
        try {
            map.stop();
        } catch {}

        const routingService = this.leafletRoutingControl.getRouter('osrm');
        const { router, formatter } = this.leafletRoutingControl.get(routingService);
        const tag = `routing:${Date.now().toString(36)}:${Math.random().toString(36).slice(2, 8)}`;

        const routingControl = new RoutingControl({
            router,
            formatter,
            waypoints,
            markerOptions: {
                icon: L.icon({
                    iconUrl: '/assets/images/marker-icon.png',
                    iconRetinaUrl: '/assets/images/marker-icon-2x.png',
                    shadowUrl: '/assets/images/marker-shadow.png',
                    iconSize: [25, 41],
                    iconAnchor: [12, 41],
                }),
            },
            alternativeClassName: 'hidden',
            addWaypoints: false,
        }).addTo(map);

        // Track routing control
        this.routingControl = routingControl;

        // Tag for removal later
        this.#tagRoutingControl(routingControl, tag);

        routingControl.on('routesfound', (event) => {
            const { routes } = event;
            options?.onRouteFound?.(routes[0]);
            this.route = routes[0];
        });

        this.positionWaypoints(waypoints);

        return routingControl;
    }

    positionWaypoints(waypoints) {
        if (waypoints.length === 1) {
            this.map.flyTo(waypoints[0], 18);
            this.map.once('moveend', () => this.map.panBy([200, 0]));
        } else {
            this.map.fitBounds(waypoints, {
                paddingBottomRight: [300, 0],
                maxZoom: waypoints.length === 2 ? 15 : 14,
                animate: true,
            });
            this.map.once('moveend', () => this.map.panBy([150, 0]));
        }
    }

    removeRoute() {
        this.route = null;
    }

    async replaceRoutingControl(waypoints, routingControl, options = {}) {
        const removeOptions = options.removeOptions ?? {};
        if (routingControl) {
            await this.removeRoutingControl(routingControl, removeOptions);
        } else {
            this.forceRemoveRoutingControl({ ...removeOptions, routingControl });
        }
        this.removeRoute();

        return this.addRoutingControl(waypoints, options);
    }

    /* eslint-disable no-empty */
    removeRoutingControl(routingControl, options = {}) {
        return new Promise((resolve) => {
            let removed = false;

            if (this.map && routingControl instanceof RoutingControl) {
                try {
                    routingControl.remove();
                    removed = true;
                } catch {}

                if (!removed) {
                    try {
                        this.map.removeControl(routingControl);
                    } catch {}
                }
            }

            if (!removed) {
                this.forceRemoveRoutingControl({ routingControl, ...options });
            }

            this.routingControl = null;
            resolve(true);
        });
    }

    /* eslint-disable no-empty */
    forceRemoveRoutingControl({ routingControl, filter } = {}) {
        if (!this.map) return;

        // If we have a control instance, prefer its tag
        const tag = routingControl?._routingTag;

        this.map.eachLayer((layer) => {
            // Only consider markers & polylines
            if (!(layer instanceof L.Marker || layer instanceof L.Polyline || layer.eachLayer)) return;

            // Must match our routing tag if present
            const tagged = tag && layer.__routingTag === tag;
            if (!tagged) return;

            // Optional: caller-provided veto
            if (typeof filter === 'function' && filter(layer) === true) return;

            try {
                // Remove the group or the individual layer
                try {
                    layer.remove();
                } catch {
                    this.map.removeLayer(layer);
                }
            } catch (_) {}
        });

        // Finally, remove the control itself if given
        try {
            routingControl?.remove?.();
        } catch (_) {}

        this.routingControl = null;
    }

    /* eslint-disable no-empty */
    #tagRoutingControl(routingControl, tag) {
        // keep the tag on the control
        routingControl._routingTag = tag;

        // After the control is on map, tag markers & lines we can find
        const tagLayer = (layer) => {
            if (!layer) return;
            layer.__routingTag = tag;
            // If it’s a LayerGroup (LRM lines are LayerGroups), tag children too
            if (layer.eachLayer) {
                layer.eachLayer((child) => (child.__routingTag = tag));
            }
        };

        // Tag plan markers
        try {
            const markers = routingControl.getPlan?.()._markers || [];
            markers.forEach(tagLayer);
        } catch (_) {}

        // Tag main line and any alternative line groups if present
        // (LRM uses LayerGroup for _line and (depending on version) for alt lines)
        try {
            tagLayer(routingControl._line);
        } catch (_) {}
        try {
            // Some builds keep alternatives in a collection; tag what’s iterable
            const maybeAlts = routingControl._alternatives || routingControl._altLine || routingControl._altLines;
            (isArray(maybeAlts) ? maybeAlts : [maybeAlts]).forEach(tagLayer);
        } catch (_) {}
    }
}
