/**
 * GeofenceService
 *
 * Refactored to use the provider-agnostic MapManagerService for all map
 * interactions (drawing, fitBounds, showDrawControl, etc.).
 *
 */
import Service, { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { debug } from '@ember/debug';
import { dasherize } from '@ember/string';
import { later } from '@ember/runloop';
import toMultiPolygon from '../utils/to-multi-polygon';

export default class GeofenceService extends Service {
    @service mapManager;
    @service store;
    @service modalsManager;
    @service serviceAreas;
    @service serviceAreaActions;
    @service zoneActions;
    @service notifications;
    @service intl;
    _draftSession = null;
    _draftCreatedHandler = null;

    @action createServiceArea() {
        this.notifications.info(this.intl.t('geofence.prompts.use-draw-controls-create-service-area'));
        this.#startDraftSession('service-area');
    }

    @action createZone(serviceArea) {
        this.notifications.info(this.intl.t('geofence.prompts.use-draw-controls-create-zone'));

        if (serviceArea.leafletCoordinates) {
            this.mapManager.fitBounds(serviceArea.leafletCoordinates, { paddingBottomRight: [300, 0], maxZoom: 15, animate: true });
        }

        this.#startDraftSession('zone', { serviceArea });
    }

    @action toggleDrawControl() {
        if (this._draftSession) {
            this.#resetDraftSession({ hideControl: true, removeDraft: true });
            return;
        }

        this.notifications.info(this.intl.t('geofence.prompts.use-draw-controls-create-service-area'));
        this.#startDraftSession('generic');
    }

    @action async editServiceArea(serviceArea) {
        const layer = serviceArea?.leafletLayer ?? this.mapManager.getOverlay(serviceArea.id);
        if (!layer) {
            this.notifications.info(this.intl.t('geofence.prompts.no-layer-found-for-resource', { resource: this.intl.t('resource.service-area') }));
            return;
        }

        try {
            this.notifications.info(this.intl.t('geofence.prompts.editing-enabled'));
            const result = await this.#editPolygonLayer(layer, { focusBounds: serviceArea.leafletCoordinates });

            if (result?.type === 'edited') {
                const border = toMultiPolygon(result.geoJson ?? result.toGeoJSON?.());
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
        const layer = zone?.leafletLayer ?? this.mapManager.getOverlay(zone.id);
        if (!layer) {
            this.notifications.info(this.intl.t('geofence.prompts.no-layer-found-for-resource', { resource: this.intl.t('resource.zone') }));
            return;
        }

        try {
            this.notifications.info(this.intl.t('geofence.prompts.editing-enabled'));
            const result = await this.#editPolygonLayer(layer, { focusBounds: zone.leafletCoordinates });

            if (result?.type === 'edited') {
                const border = result.geoJson ?? result.toGeoJSON?.();
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
        this.#showServiceArea(serviceArea, { pin: true });
        if (serviceArea.leafletCoordinates) {
            this.mapManager.fitBounds(serviceArea.leafletCoordinates, { paddingBottomRight: [0, 0], maxZoom: 15, animate: true });
        }
    }

    @action blurServiceArea(serviceArea) {
        this.#showServiceArea(serviceArea, { pin: false });
        this.#hideServiceArea(serviceArea);
    }

    @action focusZone(zone) {
        this.#showZone(zone, { pin: true });
        if (zone.leafletCoordinates) {
            this.mapManager.fitBounds(zone.leafletCoordinates, { paddingBottomRight: [0, 0], maxZoom: 15, animate: true });
        }
    }

    #startDraftSession(mode, { serviceArea = null } = {}) {
        this.#resetDraftSession();

        this._draftSession = {
            mode,
            serviceArea,
            draftLayer: null,
        };

        this.mapManager.showDrawControl({
            tools: ['polygon', 'circle', 'rectangle'],
            allowEdit: true,
            allowDelete: true,
            defaultMode: null,
        });

        this._draftCreatedHandler = (payload) => {
            this.#handleDraftCreated(payload);
        };

        this.mapManager.on('draw:created', this._draftCreatedHandler);
    }

    #handleDraftCreated({ layer, geoJson }) {
        if (!this._draftSession) return;

        this._draftSession.draftLayer = layer;
        this.mapManager.off('draw:created', this._draftCreatedHandler);
        this._draftCreatedHandler = null;

        if (this._draftSession.mode === 'service-area') {
            const border = toMultiPolygon(geoJson);
            this.serviceAreaActions.modal.create(
                { border },
                {
                    decline: (modal) => {
                        this.#resetDraftSession({ hideControl: true, removeDraft: true });
                        modal.done?.();
                    },
                },
                {
                    callback: (serviceArea) => {
                        this.#finishDraftSave((draftLayer) => {
                            this.#reloadThenFocusServiceArea(serviceArea, { draftLayer });
                        });
                    },
                    refresh: false,
                }
            );
            return;
        }

        if (this._draftSession.mode === 'zone') {
            const serviceArea = this._draftSession.serviceArea;
            this.zoneActions.modal.create(
                { border: geoJson, service_area: serviceArea },
                {
                    decline: (modal) => {
                        this.#resetDraftSession({ hideControl: true, removeDraft: true });
                        modal.done?.();
                    },
                },
                {
                    callback: (zone) => {
                        this.#finishDraftSave((draftLayer) => {
                            this.#reloadThenFocusZone(serviceArea, zone, { draftLayer });
                        });
                    },
                    refresh: false,
                }
            );
            return;
        }

        this.#openGenericLayerModal(geoJson);
    }

    #openGenericLayerModal(geoJson) {
        this.modalsManager.show('modals/map-layer-form', {
            title: 'Create new Layer',
            modalClass: 'flb-resource-modal',
            acceptButtonText: 'Create',
            acceptButtonIcon: 'magic',
            declineButtonIcon: 'times',
            declineButtonIconPrefix: 'fas',
            layerTypes: ['Service Area', 'Zone'],
            selectedLayerType: 'Service Area',
            serviceAreaTypes: this.serviceAreas.serviceAreaTypes,
            layerOptions: {
                trigger_on_entry: false,
                trigger_on_exit: false,
                dwell_threshold_minutes: null,
                speed_limit_kmh: null,
                description: null,
            },
            confirm: (modal) => {
                modal.startLoading();

                const selectedLayerType = modal.getOption('selectedLayerType');
                const layerOptions = modal.getOption('layerOptions') ?? {};
                const selectedServiceArea = layerOptions.service_area;

                if (selectedLayerType === 'Zone' && !selectedServiceArea) {
                    this.notifications.error('Service Area required to create Zone!');
                    modal.stopLoading?.();
                    return;
                }

                const record = this.store.createRecord(dasherize(selectedLayerType), layerOptions);

                if (selectedLayerType === 'Service Area') {
                    record.setProperties({
                        border: toMultiPolygon(geoJson),
                        status: 'active',
                    });
                } else {
                    record.setProperties({
                        border: geoJson,
                        service_area: selectedServiceArea,
                    });
                }

                return record.save().then((savedRecord) => {
                    if (selectedLayerType === 'Service Area') {
                        this.#finishDraftSave((draftLayer) => {
                            this.#reloadThenFocusServiceArea(savedRecord, { draftLayer });
                        });
                    } else {
                        this.#finishDraftSave((draftLayer) => {
                            this.#reloadThenFocusZone(selectedServiceArea, savedRecord, { draftLayer });
                        });
                    }

                    return savedRecord;
                });
            },
            decline: (modal) => {
                this.#resetDraftSession({ hideControl: true, removeDraft: true });
                modal.done?.();
            },
        });
    }

    #resetDraftSession({ hideControl = false, removeDraft = false } = {}) {
        if (this._draftCreatedHandler) {
            this.mapManager.off('draw:created', this._draftCreatedHandler);
            this._draftCreatedHandler = null;
        }

        const draftLayer = this._draftSession?.draftLayer;
        if (removeDraft && draftLayer) {
            this.mapManager.removeLayer(draftLayer);
        }

        if (hideControl) {
            this.mapManager.hideDrawControl();
        }

        this._draftSession = null;
    }

    #finishDraftSave(callback) {
        const draftLayer = this._draftSession?.draftLayer ?? null;
        callback?.(draftLayer);
        this.#resetDraftSession({ hideControl: true, removeDraft: false });
    }

    async #editPolygonLayer(originalLayer, { focusBounds = null } = {}) {
        return this.mapManager.editPolygon(originalLayer, { focusBounds });
    }

    #showZone(zone, { pin = false } = {}) {
        const overlay = this.mapManager.getOverlay(zone.id);
        const layer = overlay ?? zone?.leafletLayer ?? null;
        this.#setLayerPinned(layer, pin);
        if (overlay) {
            this.mapManager.showPolygon(zone.id);
        } else if (layer) {
            this.mapManager.showLayer?.(layer);
        }
    }

    #includeServiceArea(serviceArea) {
        if (!serviceArea?.id) return;

        const currentServiceAreas = this.serviceAreaActions.serviceAreas;
        if (!currentServiceAreas) {
            this.serviceAreaActions.serviceAreas = [serviceArea];
            return;
        }

        const serviceAreas = Array.from(currentServiceAreas);
        const exists = serviceAreas.some((existingServiceArea) => existingServiceArea?.id === serviceArea.id);
        if (exists) return;

        this.serviceAreaActions.serviceAreas = [...serviceAreas, serviceArea];
    }

    #includeZone(serviceArea, zone) {
        if (!serviceArea?.id || !zone?.id) return;

        const zones = serviceArea.zones;
        if (!zones) {
            serviceArea.set?.('zones', [zone]);
            this.serviceAreaActions.serviceAreas = Array.from(this.serviceAreaActions.serviceAreas ?? []);
            return;
        }

        const exists = Array.from(zones).some((existingZone) => existingZone?.id === zone.id);
        if (exists) return;

        if (typeof zones.pushObject === 'function') {
            zones.pushObject(zone);
        } else if (typeof zones.addObject === 'function') {
            zones.addObject(zone);
        } else if (typeof zones.push === 'function') {
            zones.push(zone);
        }

        this.serviceAreaActions.serviceAreas = Array.from(this.serviceAreaActions.serviceAreas ?? []);
    }

    #reloadThenFocusServiceArea(serviceArea, options = {}) {
        this.#reloadServiceAreas()
            .then((serviceAreas) => {
                const canonicalServiceArea = this.#findResource(serviceAreas, serviceArea) ?? serviceArea;
                this.#focusServiceAreaAfterCreate(canonicalServiceArea, options);
            })
            .catch(() => {
                this.#includeServiceArea(serviceArea);
                this.#focusServiceAreaAfterCreate(serviceArea, options);
            });
    }

    #reloadThenFocusZone(serviceArea, zone, options = {}) {
        this.#reloadServiceAreas()
            .then((serviceAreas) => {
                const canonicalServiceArea = this.#findResource(serviceAreas, serviceArea) ?? serviceArea;
                const canonicalZone = this.#findResource(canonicalServiceArea?.zones, zone) ?? zone;
                this.#focusZoneAfterCreate(canonicalZone, options);
            })
            .catch(() => {
                this.#includeZone(serviceArea, zone);
                this.#focusZoneAfterCreate(zone, options);
            });
    }

    #reloadServiceAreas() {
        return Promise.resolve(this.serviceAreaActions.loadAll.perform()).then(() => this.serviceAreaActions.serviceAreas ?? []);
    }

    #findResource(resources, resource) {
        if (!resource || !resources) return null;

        const resourceId = resource.id ?? resource.public_id;
        const publicId = resource.public_id ?? resource.id;

        return (
            Array.from(resources).find((candidate) => {
                return candidate?.id === resourceId || candidate?.id === publicId || candidate?.public_id === publicId || candidate?.public_id === resourceId;
            }) ?? null
        );
    }

    #focusServiceAreaAfterCreate(serviceArea, options = {}) {
        this.#focusWhenReady([serviceArea], () => this.focusServiceArea(serviceArea), options);
    }

    #focusZoneAfterCreate(zone, options = {}) {
        this.#focusWhenReady([zone], () => this.focusZone(zone), options);
    }

    #focusWhenReady(models, focus, { attempts = 60, delay = 100, draftLayer = null } = {}) {
        const overlays = models
            .map((model) => {
                const overlay = this.mapManager.getOverlay(model?.id);
                if (overlay) {
                    return overlay;
                }

                return model?.leafletLayer === draftLayer ? null : model?.leafletLayer;
            })
            .filter(Boolean);
        const allRegistered = overlays.length === models.length;

        if (allRegistered || attempts <= 0) {
            focus();
            if (draftLayer && !draftLayer.__removedAfterCreate && !overlays.includes(draftLayer)) {
                draftLayer.__removedAfterCreate = true;
                this.mapManager.removeLayer(draftLayer);
            }
            return;
        }

        later(this, () => this.#focusWhenReady(models, focus, { attempts: attempts - 1, delay, draftLayer }), delay);
    }

    #showServiceArea(serviceArea, { pin = false } = {}) {
        [serviceArea, ...(serviceArea.zones ?? [])].forEach((model) => {
            const overlay = this.mapManager.getOverlay(model.id);
            const layer = overlay ?? model?.leafletLayer ?? null;
            this.#setLayerPinned(layer, pin);
            if (overlay) {
                this.mapManager.showPolygon(model.id);
            } else if (layer) {
                this.mapManager.showLayer?.(layer);
            }
        });
    }

    #hideServiceArea(serviceArea) {
        [serviceArea, ...(serviceArea.zones ?? [])].forEach((model) => {
            const overlay = this.mapManager.getOverlay(model.id);
            const layer = model?.leafletLayer;

            this.#setLayerPinned(layer ?? overlay, false);
            if (overlay) {
                this.mapManager.hidePolygon(model.id);
            }

            if (layer && layer !== overlay) {
                this.mapManager.hideLayer?.(layer);
            }
        });
    }

    #setLayerPinned(layer, pinned = false) {
        if (!layer) return;
        layer.__pinnedByFocus = pinned;
    }
}
