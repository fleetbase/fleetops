import Service, { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { debug } from '@ember/debug';
import { createGeoJsonFromLayer } from '../utils/leaflet-to-geojson';
import toMultiPolygon from '../utils/to-multi-polygon';

export default class GeofenceService extends Service {
    @service leafletMapManager;
    @service leafletLayerVisibilityManager;
    @service serviceAreaActions;
    @service zoneActions;
    @service notifications;
    @service intl;
    #currentEdit = null;

    @action createServiceArea() {
        this.notifications.info(this.intl.t('geofence.prompts.use-draw-controls-create-service-area'));
        this.leafletMapManager.showDrawControl();
        this.leafletMapManager.map.once('draw:created', ({ layer, layerType }) => {
            const border = toMultiPolygon(createGeoJsonFromLayer(layer, { layerType }));

            this.serviceAreaActions.modal.create(
                { border },
                {
                    saveOptions: {
                        callback: (serviceArea) => {
                            this.leafletMapManager.hideDrawControl();
                            this.#showServiceArea(serviceArea);
                        },
                    },
                }
            );
        });
    }

    @action createZone(serviceArea) {
        this.notifications.info(this.intl.t('geofence.prompts.use-draw-controls-create-zone'));
        this.leafletMapManager.showDrawControl();
        this.leafletMapManager.map.fitBounds(serviceArea.leafletCoordinates, {
            paddingBottomRight: [300, 0],
            maxZoom: 15,
            animate: true,
        });
        // @todo use restriction service to restrict drawing to polygon
        // this.leafletDrawRestriction.setDrawRestrictionPolygon(serviceArea.leafletLayer);
        this.leafletMapManager.map.once('draw:created', ({ layer, layerType }) => {
            const border = createGeoJsonFromLayer(layer, { layerType });

            this.zoneActions.modal.create(
                { border, service_area_uuid: serviceArea.id },
                {
                    saveOptions: {
                        callback: (zone) => {
                            this.leafletMapManager.hideDrawControl();
                            this.#showZone(zone);
                        },
                    },
                }
            );
        });
    }

    @action async editServiceArea(serviceArea) {
        const layer = serviceArea?.leafletLayer;
        if (!layer) {
            this.notifications.info(this.intl.t('geofence.prompts.no-layer-found-for-resource', { resource: this.intl.t('resource.service-area') }));
            return;
        }

        try {
            this.notifications.info(this.intl.t('geofence.prompts.editing-enabled'));
            const result = await this.#editPolygonLayer(layer, { focusBounds: serviceArea.leafletCoordinates });

            if (result?.type === 'edited') {
                const border = toMultiPolygon(createGeoJsonFromLayer(result.layer));
                serviceArea.set('border', border);
                await serviceArea.save?.();

                this.notifications.success(this.intl.t('geofence.prompts.resource-boundaries-updated', { resource: this.intl.t('resource.service-area') }));
            } else {
                this.notifications.info(this.intl.t('geofence.prompts.edit-canceled'));
            }
        } catch (e) {
            debug(`editServiceArea error: ${e?.message ?? e}`);
            this.notifications.serverError?.(e) || this.notifications.error?.(this.intl.t('geofence.prompts.failed-to-update-resource', { resource: this.intl.t('resource.service-area') }));
        }
    }

    @action async editZone(zone) {
        const layer = zone?.leafletLayer;
        if (!layer) {
            this.notifications.info(this.intl.t('geofence.prompts.no-layer-found-for-resource', { resource: this.intl.t('resource.zone') }));
            return;
        }

        try {
            this.notifications.info(this.intl.t('geofence.prompts.editing-enabled'));
            const result = await this.#editPolygonLayer(layer, { focusBounds: zone.leafletCoordinates });

            if (result?.type === 'edited') {
                const border = createGeoJsonFromLayer(result.layer);
                zone.set('border', border);
                await zone.save?.();

                this.notifications.success(this.intl.t('geofence.prompts.resource-boundaries-updated', { resource: this.intl.t('resource.zone') }));
            } else {
                this.notifications.info(this.intl.t('geofence.prompts.edit-canceled'));
            }
        } catch (e) {
            debug(`editZone error: ${e?.message ?? e}`);
            this.notifications.serverError?.(e) || this.notifications.error?.(this.intl.t('geofence.prompts.failed-to-update-resource', { resource: this.intl.t('resource.zone') }));
        }
    }

    @action showAllServiceAreas() {
        this.serviceAreaActions.serviceAreas.forEach((serviceArea) => {
            this.#showServiceArea(serviceArea);
        });
    }

    @action hideAllServiceAreas() {
        this.serviceAreaActions.serviceAreas.forEach((serviceArea) => {
            this.#hideServiceArea(serviceArea);
        });
    }

    @action focusServiceArea(serviceArea) {
        this.#showServiceArea(serviceArea);
        this.leafletMapManager.map.fitBounds(serviceArea.leafletCoordinates, {
            paddingBottomRight: [0, 0],
            maxZoom: 15,
            animate: true,
        });
    }

    @action blurServiceArea(serviceArea) {
        this.#hideServiceArea(serviceArea);
    }

    @action focusZone(zone) {
        this.leafletMapManager.showLayer(zone.leafletLayer);
        this.leafletMapManager.map.fitBounds(zone.leafletCoordinates, {
            paddingBottomRight: [0, 0],
            maxZoom: 15,
            animate: true,
        });
    }

    /* eslint-disable no-empty */
    async #editPolygonLayer(originalLayer, { focusBounds = null } = {}) {
        const map = this.leafletMapManager.map;
        const draw = this.leafletMapManager.drawControl;
        const group = this.leafletMapManager.drawControlFeatureGroup;
        if (!map || !draw || !group || !originalLayer) {
            throw new Error('Geofence edit: map/draw/editGroup/layer not available');
        }

        // Make layer visible if it wasn'e
        this.leafletLayerVisibilityManager.showLayer(originalLayer);

        // end any previous edit session cleanly
        if (this._currentEdit) {
            try {
                draw?._toolbars?.edit?._modes?.edit?.handler?.disable();
            } catch {}
            try {
                group.removeLayer(this._currentEdit.proxy);
            } catch {}
            // restore visibility of previous original
            if (this._currentEdit.wasVisible !== false) {
                this.leafletLayerVisibilityManager.showLayer(this._currentEdit.original);
            }
            this._currentEdit = null;
        }

        // Build a lightweight editable clone ("proxy") — DO NOT re-parent the original
        // Keep style minimal; Draw handler only needs something editable.
        const latlngs = originalLayer.getLatLngs?.();
        if (!latlngs || !latlngs.length) {
            throw new Error('Geofence edit: layer has no coordinates');
        }

        const proxy = L.polygon(latlngs, {
            // neutral styling so user sees edits; tweak to your theme if needed
            color: originalLayer.options?.color || '#3388ff',
            weight: 3,
            opacity: 0.9,
            fill: true,
            fillOpacity: originalLayer.options?.fillOpacity ?? 0.2,
        });

        // Hide original while editing so tooltips/hover don’t collide
        const wasVisible = !originalLayer.__hidden;
        this.leafletLayerVisibilityManager.hideLayer(originalLayer, { soft: false });

        // Add proxy to the edit FeatureGroup (this re-parents only the proxy)
        group.addLayer(proxy);

        // Optional focus
        if (focusBounds) {
            try {
                map.fitBounds(focusBounds, { paddingBottomRight: [0, 0], maxZoom: 16, animate: true });
            } catch {}
        }

        // Enable edit mode just once for the group
        this.leafletMapManager.showDrawControl?.();
        const editHandler = draw?._toolbars?.edit?._modes?.edit?.handler;
        if (!editHandler) throw new Error('Geofence edit: edit handler unavailable');
        editHandler.enable();

        this._currentEdit = { original: originalLayer, proxy, wasVisible };

        return new Promise((resolve) => {
            let settled = false;

            const cleanup = (result) => {
                if (settled) return;
                settled = true;

                // Disable edit mode
                try {
                    editHandler.disable();
                } catch {}

                // Remove proxy from group & map
                try {
                    group.removeLayer(proxy);
                } catch {}
                try {
                    proxy.remove();
                } catch {}

                // Restore original visibility
                if (wasVisible) {
                    this.leafletLayerVisibilityManager.showLayer(originalLayer, { soft: false });
                }

                // Hide the toolbox again
                this.leafletMapManager.hideDrawControl?.();

                this._currentEdit = null;
                resolve(result);
            };

            // User pressed the ✓ in the toolbar; Leaflet.draw emits draw:edited
            const onEdited = (evt) => {
                // Apply edited coords back to original
                try {
                    const edited = proxy.getLatLngs?.();
                    if (edited?.length) {
                        originalLayer.setLatLngs(edited);
                        // force a redraw so ember-leaflet’s Polygon reflects new path
                        originalLayer.redraw?.();
                    }
                } catch {}
                cleanup({ type: 'edited', layer: originalLayer, proxy, event: evt });
            };

            const onEditStop = () => {
                // If user exits edit mode without applying, treat as cancel
                cleanup({ type: 'cancel' });
            };

            map.once('draw:edited', onEdited);
            map.once('draw:editstop', onEditStop);
        });
    }

    #showZone(zone) {
        this.leafletLayerVisibilityManager.showModelLayer(zone);
    }

    #showServiceArea(serviceArea) {
        [serviceArea, ...(serviceArea.zones ?? [])].forEach((model) => {
            this.leafletLayerVisibilityManager.showModelLayer(model);
        });
    }

    #hideServiceArea(serviceArea) {
        [serviceArea, ...(serviceArea.zones ?? [])].forEach((model) => {
            this.leafletLayerVisibilityManager.hideModelLayer(model);
        });
    }
}
