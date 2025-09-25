import Service from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { isArray } from '@ember/utils';
import { Control as RoutingControl } from '@fleetbase/leaflet-routing-machine';
import getLeafletLayerById from '../utils/get-leaflet-layer-by-id';
import findLeafletLayer from '../utils/find-leaflet-layer';
import flyToLeafletLayer from '../utils/fly-to-leaflet-layer';

/**
 * Service for managing Leaflet maps and layers.
 *
 * This service provides functions to work with Leaflet maps, including accessing layers by ID,
 * finding layers based on a custom callback, and flying to specific layers with optional zoom and options.
 *
 * @class
 */
export default class LeafletMapManagerService extends Service {
    /**
     * The current leaflet map instance.
     *
     * @memberof LeafletMapManagerService
     */
    @tracked map;

    /**
     * The current route control instance.
     *
     * @memberof LeafletMapManagerService
     */
    @tracked route;

    /**
     * Tracking of current leaflet layers.
     *
     * @memberof LeafletMapManagerService
     * @type {Array}
     */
    @tracked leafletLayers = [];

    /**
     * An array of editable layers on the map.
     *
     * @memberof LeafletMapManagerService
     * @type {Array}
     */
    @tracked editableLayers = [];

    /**
     * Get a Leaflet layer by its ID from the given map.
     *
     * @memberof LeafletMapManagerService
     * @param {Object} map - The Leaflet map instance.
     * @param {string} layerId - The ID of the layer to retrieve.
     * @returns {Object|null} The Leaflet layer with the specified ID, or null if not found.
     */
    getLeafletLayerById(map, layerId) {
        return getLeafletLayerById(map, layerId);
    }

    /**
     * Find a Leaflet layer in the given map using a custom callback.
     *
     * @memberof LeafletMapManagerService
     * @param {Object} map - The Leaflet map instance.
     * @param {Function} findCallback - A custom callback function to find the desired layer.
     * @returns {Object|null} The found Leaflet layer, or null if not found.
     */
    findLeafletLayer(map, findCallback) {
        return findLeafletLayer(map, findCallback);
    }

    /**
     * Fly to a specific Leaflet layer on the map with optional zoom and options.
     *
     * @memberof LeafletMapManagerService
     * @param {Object} map - The Leaflet map instance.
     * @param {Object} layer - The Leaflet layer to fly to.
     * @param {number} zoom - The zoom level to apply (optional).
     * @param {Object} options - Additional options for the fly animation (optional).
     * @returns {Object} The Leaflet map instance.
     */
    flyToLayer(map, layer, zoom, options = {}) {
        return flyToLeafletLayer(map, layer, zoom, options);
    }

    removeAllLayers(options = {}) {
        if (this.map) {
            try {
                this.map.eachLayer((layer) => {
                    if (typeof options.filter === 'function') {
                        const shouldNotRemove = options.filter(layer);
                        if (shouldNotRemove) {
                            return;
                        }
                    }
                    this.map.removeLayer(layer);
                });
            } catch (error) {
                // fallback method with tracked layers
                if (isArray(this.leafletLayers)) {
                    this.leafletLayers.forEach((layer) => {
                        if (typeof options.filter === 'function') {
                            const shouldNotRemove = options.filter(layer);
                            if (shouldNotRemove) {
                                return;
                            }
                        }

                        try {
                            this.map.removeLayer(layer);
                        } catch (error) {}
                    });
                }
            }
        }
    }

    removeRoute() {
        if (this.route) {
            // target is the route, and waypoints is the markers
            const { target, waypoints } = this.route;

            this.map.removeLayer(target);
            waypoints?.forEach((waypoint) => {
                try {
                    this.map.removeLayer(waypoint);
                } catch (error) {}
            });
        }
    }

    removeRoutingControl(routingControl, options = {}) {
        return new Promise((resolve) => {
            let removed = false;

            if (this.map && routingControl instanceof RoutingControl) {
                try {
                    routingControl.remove();
                    removed = true;
                } catch (e) {}

                if (!removed) {
                    try {
                        this.map.removeControl(routingControl);
                    } catch (e) {
                        // silent
                    }
                }
            }

            if (!removed) {
                this.forceRemoveRoutingControl(options);
            }

            resolve(true);
        });
    }

    forceRemoveRoutingControl(options = {}) {
        this.map.eachLayer((layer) => {
            if (layer instanceof L.Polyline || layer instanceof L.Marker) {
                try {
                    if (typeof options.filter === 'function') {
                        const shouldNotRemove = options.filter(layer);
                        if (shouldNotRemove) {
                            return;
                        }
                    }

                    layer.remove();
                } catch (error) {}
            }
        });
    }
}
