import Component from '@glimmer/component';
import { action, get } from '@ember/object';
import { later, cancel } from '@ember/runloop';

/**
 * A service area's or zone's boundary on a map, fitted to the whole shape.
 *
 * The details panels open inside a sliding overlay, so Leaflet first measures
 * a container with no size: the tiles stay grey and the view is centred on
 * the wrong point. The map is re-measured and re-fitted once the overlay has
 * settled, and again whenever its container changes size.
 */
export default class GeofenceMapComponent extends Component {
    map = null;
    resizeObserver = null;
    timer = null;

    /** Each polygon's outer ring as Leaflet `[lat, lng]` pairs. */
    get polygons() {
        const border = this.args.resource ? get(this.args.resource, 'border') : null;
        const type = border?.type;
        const coordinates = border?.coordinates ?? [];
        const rings = type === 'MultiPolygon' ? coordinates.map((polygon) => polygon?.[0] ?? []) : [coordinates[0] ?? []];

        return rings.map((ring) => ring.filter((pair) => Array.isArray(pair) && pair.length >= 2).map(([lng, lat]) => [lat, lng])).filter((ring) => ring.length > 0);
    }

    /** `[[south, west], [north, east]]` around every polygon, or null. */
    get bounds() {
        const points = this.polygons.flat();
        if (!points.length) {
            return null;
        }

        const lats = points.map(([lat]) => lat);
        const lngs = points.map(([, lng]) => lng);

        return [
            [Math.min(...lats), Math.min(...lngs)],
            [Math.max(...lats), Math.max(...lngs)],
        ];
    }

    get center() {
        const bounds = this.bounds;
        if (!bounds) {
            return [0, 0];
        }

        return [(bounds[0][0] + bounds[1][0]) / 2, (bounds[0][1] + bounds[1][1]) / 2];
    }

    @action didLoadMap({ target: map }) {
        this.map = map;
        this.fit();
        requestAnimationFrame(() => this.fit());
        this.timer = later(this, this.fit, 350);

        if (typeof ResizeObserver !== 'undefined' && typeof map.getContainer === 'function') {
            this.resizeObserver = new ResizeObserver(() => this.fit());
            this.resizeObserver.observe(map.getContainer());
        }
    }

    fit() {
        if (this.isDestroying || this.isDestroyed || !this.map) {
            return;
        }

        this.map.invalidateSize();

        if (this.bounds) {
            this.map.fitBounds(this.bounds, { padding: [24, 24] });
        }
    }

    willDestroy() {
        super.willDestroy(...arguments);
        cancel(this.timer);
        this.resizeObserver?.disconnect();
        this.map = null;
    }
}
