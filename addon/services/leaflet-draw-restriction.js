import Service from '@ember/service';
import booleanPointInPolygon from '@turf/boolean-point-in-polygon';
import { point as turfPoint, polygon as turfPolygon } from '@turf/helpers';

const L = window.leaflet || window.L;

export default class LeafletMapManagerService extends Service {
    #restrictionPolyLayer = null; // L.Polygon the user must stay inside
    #restrictionPolyTurf = null; // turf polygon for fast tests
    #unsub = []; // to clean up listeners

    setDrawRestrictionPolygon(layer) {
        this.#restrictionPolyLayer = layer;
        // Convert Leaflet latlngs -> [lng,lat] rings
        const rings = layer.getLatLngs()[0].map((ll) => [ll.lng, ll.lat]);
        // Ensure ring is closed
        if (rings.length && (rings[0][0] !== rings.at(-1)[0] || rings[0][1] !== rings.at(-1)[1])) {
            rings.push(rings[0]);
        }
        this.#restrictionPolyTurf = turfPolygon([rings]);
        this.#bindRestrictionGuards();
    }

    clearDrawRestriction() {
        this.#restrictionPolyLayer = null;
        this.#restrictionPolyTurf = null;
        this.#unsub.forEach((off) => off());
        this.#unsub = [];
    }

    /** Attach guards for draw + edit */
    #bindRestrictionGuards() {
        if (!this.map || !this.#restrictionPolyTurf) return;
        this.#unsub.forEach((off) => off());
        this.#unsub = [];

        const off = (evt, fn) => {
            this.map.off(evt, fn);
        };
        const on = (evt, fn) => {
            this.map.on(evt, fn);
            this.#unsub.push(() => off(evt, fn));
        };

        // While drawing vertices, prevent adding points outside
        on(L.Draw.Event.DRAWVERTEX, (e) => {
            // e.layers is a FeatureGroup of temp markers; last one is the new vertex
            let last = null;
            e.layers?.eachLayer((l) => {
                last = l;
            });
            const latlng = last?.getLatLng?.();
            if (!latlng) return;

            if (!this.#isInside(latlng)) {
                // remove the bad vertex and give feedback
                e.layers.removeLayer(last);
                this.notifications?.warning?.('Vertex must be inside the service area.');
            }
        });

        // When a shape is finished, validate the whole geometry
        on(L.Draw.Event.CREATED, (e) => {
            const { layer, layerType } = e;

            if (layerType === 'polygon' || layerType === 'rectangle') {
                const ok = this.#everyVertexInside(layer.getLatLngs());
                if (!ok) {
                    this.map.removeLayer(layer);
                    this.notifications?.error?.('Shape must stay inside the service area.');
                }
            } else if (layerType === 'polyline') {
                const ok = this.#everyVertexInside(layer.getLatLngs());
                if (!ok) {
                    this.map.removeLayer(layer);
                    this.notifications?.error?.('Line must stay inside the service area.');
                }
            } else if (layerType === 'circle') {
                const ok = this.#circleInside(layer);
                if (!ok) {
                    this.map.removeLayer(layer);
                    this.notifications?.error?.('Circle must stay inside the service area.');
                }
            }
        });

        // While editing vertices, block moves that escape
        on(L.Draw.Event.EDITMOVE, (e) => {
            const layer = e.layer;
            if (!layer) return;

            let ok = true;
            if (layer instanceof L.Polygon || layer instanceof L.Polyline || layer instanceof L.Rectangle) {
                ok = this.#everyVertexInside(layer.getLatLngs());
            } else if (layer instanceof L.Circle) {
                ok = this.#circleInside(layer);
            }

            if (!ok) {
                // revert the change by calling the internal reset (Leaflet.draw keeps _latlngsBeforeEdit)
                layer.editor?.reset?.(); // if using path editor
                layer._bounds && layer.setLatLngs(layer._latlngsBeforeEdit || layer.getLatLngs());
                this.notifications?.warning?.('Edit keeps shape inside the service area.');
            }
        });

        on(L.Draw.Event.EDITVERTEX, (e) => {
            // similar to EDITMOVE but fires for single vertex moves
            const layer = e.layer || e.poly;
            if (!layer) return;
            const ok = layer instanceof L.Circle ? this.#circleInside(layer) : this.#everyVertexInside(layer.getLatLngs());
            if (!ok) {
                layer.editor?.reset?.();
                this.notifications?.warning?.('Vertex must stay inside the service area.');
            }
        });
    }

    #isInside(latlng) {
        if (!this.#restrictionPolyTurf) return true;
        return booleanPointInPolygon(turfPoint([latlng.lng, latlng.lat]), this.#restrictionPolyTurf);
    }

    #everyVertexInside(latlngs) {
        // latlngs can be nested (poly: [ring], multi: [[ring]...])
        const walk = (arr) => {
            if (!Array.isArray(arr)) return true;
            if (arr.length && arr[0]?.lat != null) {
                return arr.every((ll) => this.#isInside(ll));
            }
            return arr.every(walk);
        };
        return walk(latlngs);
    }

    #circleInside(circle) {
        // quick check: ensure a few perimeter samples are inside
        const c = circle.getLatLng();
        const r = circle.getRadius();
        const samples = 16;
        for (let i = 0; i < samples; i++) {
            const angle = (i / samples) * 2 * Math.PI;
            const dx = (r / 111320) * Math.cos(angle); // ~ meters to deg lon at equator
            const dy = (r / 110540) * Math.sin(angle); // ~ meters to deg lat
            const p = L.latLng(c.lat + dy, c.lng + dx / Math.cos((c.lat * Math.PI) / 180));
            if (!this.#isInside(p)) return false;
        }
        return true;
    }
}
