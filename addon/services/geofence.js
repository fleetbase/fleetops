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
                        this.#resetDraftSession({ hideControl: true, removeDraft: true });
                        this.#showServiceArea(serviceArea);
                    },
                }
            );
            return;
        }

        if (this._draftSession.mode === 'zone') {
            const serviceArea = this._draftSession.serviceArea;
            this.zoneActions.modal.create(
                { border: geoJson, service_area_uuid: serviceArea.id },
                {
                    decline: (modal) => {
                        this.#resetDraftSession({ hideControl: true, removeDraft: true });
                        modal.done?.();
                    },
                },
                {
                    callback: (zone) => {
                        serviceArea?.zones?.pushObject?.(zone);
                        this.#resetDraftSession({ hideControl: true, removeDraft: true });
                        this.#showZone(zone);
                    },
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
                        service_area_uuid: selectedServiceArea.id,
                        serviceArea: selectedServiceArea,
                    });
                }

                return record.save().then((savedRecord) => {
                    this.#resetDraftSession({ hideControl: true, removeDraft: true });

                    if (selectedLayerType === 'Service Area') {
                        this.#showServiceArea(savedRecord);
                    } else {
                        selectedServiceArea?.zones?.pushObject?.(savedRecord);
                        this.#showZone(savedRecord);
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

    async #editPolygonLayer(originalLayer, { focusBounds = null } = {}) {
        return this.mapManager.editPolygon(originalLayer, { focusBounds });
    }

    #showZone(zone, { pin = false } = {}) {
        const layer = this.mapManager.getOverlay(zone.id) ?? zone?.leafletLayer ?? null;
        this.#setLayerPinned(layer, pin);
        this.mapManager.showPolygon(zone.id);
    }

    #showServiceArea(serviceArea, { pin = false } = {}) {
        [serviceArea, ...(serviceArea.zones ?? [])].forEach((model) => {
            const layer = this.mapManager.getOverlay(model.id) ?? model?.leafletLayer ?? null;
            this.#setLayerPinned(layer, pin);
            this.mapManager.showPolygon(model.id);
        });
    }

    #hideServiceArea(serviceArea) {
        [serviceArea, ...(serviceArea.zones ?? [])].forEach((model) => {
            this.#setLayerPinned(model?.leafletLayer ?? this.mapManager.getOverlay(model.id), false);
            this.mapManager.hidePolygon(model.id);
        });
    }

    #setLayerPinned(layer, pinned = false) {
        if (!layer) return;
        layer.__pinnedByFocus = pinned;
    }
}
