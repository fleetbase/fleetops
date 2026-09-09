import ResourceActionService, { inject as service } from '@fleetbase/ember-core/services/resource-action';
import leafletIcon from '@fleetbase/ember-core/utils/leaflet-icon';
import config from 'ember-get-config';
import { action } from '@ember/object';
import { isArray } from '@ember/array';
import { dasherize } from '@ember/string';

/**
 * Trailer resource actions.
 *
 * Mirrors the Vehicle action service so Trailers behave like every other first-class
 * Fleet-Ops resource: route transitions, context panels, modals, and the towing,
 * device, equipment and maintenance actions that are specific to trailers.
 */
export default class TrailerActionsService extends ResourceActionService {
    @service('universe/menu-service') menuService;
    @service fetch;
    @service maintenanceScheduleActions;
    @service workOrderActions;
    @service maintenanceActions;

    constructor() {
        super(...arguments);
        this.initialize('trailer', {
            defaultAttributes: {
                status: 'available',
                asset_class: 'trailer',
                measurement_system: 'metric',
            },
        });
    }

    get registeredTabs() {
        const registeredTabs = this.menuService.getMenuItems('fleet-ops:component:trailer:details');

        return (isArray(registeredTabs) ? registeredTabs : [])
            .map((tab) => {
                delete tab.route;
                if (!tab.key) {
                    tab.key = tab.id ?? dasherize(tab.label ?? tab.title);
                }
                return tab;
            })
            .filter((tab) => !tab.component);
    }

    get panelTabs() {
        return [
            { key: 'overview', label: this.intl.t('trailer.tabs.overview'), component: 'trailer/details' },
            { key: 'positions', label: this.intl.t('trailer.tabs.positions'), component: 'positions-replay' },
            { key: 'devices', label: this.intl.t('trailer.tabs.devices'), component: 'device/manager' },
            { key: 'equipment', label: this.intl.t('trailer.tabs.equipment'), component: 'trailer/details/equipment' },
            { key: 'connections', label: this.intl.t('trailer.tabs.connections'), component: 'trailer/details/connections' },
            { key: 'schedules', label: this.intl.t('trailer.tabs.schedules'), component: 'trailer/details/schedules' },
            { key: 'work-orders', label: this.intl.t('trailer.tabs.work-orders'), component: 'trailer/details/work-orders' },
            { key: 'maintenance-history', label: this.intl.t('trailer.tabs.maintenance'), component: 'trailer/details/maintenance-history' },
            ...this.registeredTabs,
        ];
    }

    async resolveTrailerResource(trailer) {
        if (typeof trailer?.then === 'function') {
            return await trailer;
        }

        if (typeof trailer?.loadResource === 'function') {
            return (await trailer.loadResource()) ?? trailer;
        }

        return trailer;
    }

    trailerName(trailer) {
        return trailer?.displayName ?? trailer?.display_name ?? trailer?.name ?? trailer?.public_id ?? this.intl.t('resource.trailer');
    }

    vehicleName(vehicle) {
        return vehicle?.displayName ?? vehicle?.display_name ?? vehicle?.name ?? vehicle?.plate_number ?? this.intl.t('resource.vehicle');
    }

    transition = {
        view: (trailer) => this.transitionTo('management.trailers.index.details', trailer),
        edit: (trailer) => this.transitionTo('management.trailers.index.edit', trailer),
        create: () => this.transitionTo('management.trailers.index.new'),
    };

    panel = {
        create: (attributes = {}, options = {}) => {
            const trailer = this.createNewInstance(attributes);

            return this.resourceContextPanel.open({
                content: 'trailer/form',
                title: this.intl.t('common.create-a-new-resource', { resource: this.intl.t('resource.trailer')?.toLowerCase() }),
                saveOptions: { callback: this.refresh },
                useDefaultSaveTask: true,
                trailer,
                ...options,
            });
        },
        edit: async (trailer, options = {}) => {
            trailer = await this.resolveTrailerResource(trailer);

            return this.resourceContextPanel.open({
                content: 'trailer/form',
                title: this.intl.t('common.edit-resource-name', { resourceName: this.trailerName(trailer) }),
                actionButtons: [
                    {
                        icon: 'eye',
                        fn: async () => {
                            await this.resourceContextPanel.closeAll();
                            this.panel.view(trailer);
                        },
                    },
                ],
                useDefaultSaveTask: true,
                trailer,
                ...options,
            });
        },
        view: async (trailer, options = {}) => {
            trailer = await this.resolveTrailerResource(trailer);

            return this.resourceContextPanel.open({
                trailer,
                header: 'trailer/panel-header',
                actionButtons: [
                    {
                        icon: 'pencil',
                        fn: async () => {
                            await this.resourceContextPanel.closeAll();
                            this.panel.edit(trailer);
                        },
                    },
                ],
                tabs: this.panelTabs,
                ...options,
            });
        },
    };

    modal = {
        create: (attributes = {}, options = {}, saveOptions = {}) => {
            const trailer = this.createNewInstance(attributes);

            return this.modalsManager.show('modals/resource', {
                resource: trailer,
                title: this.intl.t('common.create-a-new-resource', { resource: this.intl.t('resource.trailer')?.toLowerCase() }),
                acceptButtonText: this.intl.t('common.create-resource', { resource: this.intl.t('resource.trailer') }),
                component: 'trailer/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', trailer, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        edit: async (trailer, options = {}, saveOptions = {}) => {
            trailer = await this.resolveTrailerResource(trailer);

            return this.modalsManager.show('modals/resource', {
                resource: trailer,
                title: this.intl.t('common.edit-resource-name', { resourceName: this.trailerName(trailer) }),
                acceptButtonText: this.intl.t('common.save-changes'),
                saveButtonIcon: 'save',
                component: 'trailer/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', trailer, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        view: async (trailer, options = {}) => {
            trailer = await this.resolveTrailerResource(trailer);

            return this.modalsManager.show('modals/resource', {
                resource: trailer,
                title: this.trailerName(trailer),
                component: 'trailer/details',
                ...options,
            });
        },
    };

    /**
     * Couple the trailer to a vehicle. The modal collects the vehicle and the towing
     * position; the server enforces single-attachment and position conflicts.
     */
    @action attachVehicle(trailer, options = {}) {
        const trailerName = this.trailerName(trailer);

        this.modalsManager.show('modals/attach-trailer', {
            title: this.intl.t('trailer.prompts.attach-vehicle-title', { trailerName }),
            acceptButtonText: this.intl.t('trailer.actions.attach-vehicle'),
            acceptButtonIcon: 'link',
            trailer,
            selectedVehicle: null,
            position: 1,
            confirm: async (modal) => {
                const vehicle = modal.getOption('selectedVehicle');
                const position = Number(modal.getOption('position')) || 1;

                if (!vehicle) {
                    return this.notifications.warning(this.intl.t('trailer.prompts.select-vehicle-warning'));
                }

                modal.startLoading();

                try {
                    await this.fetch.post(`trailers/${trailer.id}/attach`, { vehicle: vehicle.id, position });
                    await trailer.reload?.();
                    this.notifications.success(this.intl.t('trailer.prompts.attach-vehicle-success', { trailerName, vehicleName: this.vehicleName(vehicle) }));
                    modal.done();
                    this.refresh();
                    options.callback?.(trailer, vehicle);
                } catch (error) {
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
            ...options,
        });
    }

    /**
     * End the active towing connection. History is preserved server-side.
     */
    @action detachVehicle(trailer, options = {}) {
        const trailerName = this.trailerName(trailer);
        const vehicleName = trailer.current_vehicle_name ?? this.vehicleName(trailer.current_vehicle);

        if (!trailer.isAttached && trailer.attachment_state !== 'attached') {
            return this.notifications.warning(this.intl.t('trailer.prompts.not-attached-warning', { trailerName }));
        }

        return this.modalsManager.confirm({
            title: this.intl.t('trailer.prompts.detach-vehicle-title', { trailerName }),
            body: this.intl.t('trailer.prompts.detach-vehicle-body', { trailerName, vehicleName }),
            acceptButtonText: this.intl.t('trailer.actions.detach-vehicle'),
            acceptButtonIcon: 'unlink',
            acceptButtonScheme: 'danger',
            confirm: async (modal) => {
                modal.startLoading();

                try {
                    await this.fetch.post(`trailers/${trailer.id}/detach`);
                    await trailer.reload?.();
                    this.notifications.success(this.intl.t('trailer.prompts.detach-vehicle-success', { trailerName, vehicleName }));
                    modal.done();
                    this.refresh();
                    options.callback?.(trailer);
                } catch (error) {
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
            ...options,
        });
    }

    @action attachDevice(trailer, options = {}) {
        const trailerName = this.trailerName(trailer);

        this.modalsManager.show('modals/attach-device', {
            title: this.intl.t('trailer.prompts.attach-device-title', { trailerName }),
            acceptButtonText: this.intl.t('trailer.actions.attach-device'),
            acceptButtonIcon: 'link',
            selectedDevice: null,
            trailer,
            confirm: async (modal) => {
                const selectedDevice = modal.getOption('selectedDevice');

                if (!selectedDevice) {
                    return this.notifications.warning(this.intl.t('trailer.prompts.select-device-warning'));
                }

                modal.startLoading();

                try {
                    await this.fetch.post(`trailers/${trailer.id}/attach-device`, { device: selectedDevice.id });
                    await trailer.reload?.();
                    this.notifications.success(this.intl.t('trailer.prompts.attach-device-success', { trailerName }));
                    modal.done();
                    this.refresh();
                    options.callback?.(trailer, selectedDevice);
                } catch (error) {
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
            ...options,
        });
    }

    @action attachEquipment(trailer, options = {}) {
        const trailerName = this.trailerName(trailer);

        this.modalsManager.show('modals/attach-equipment', {
            title: this.intl.t('trailer.prompts.attach-equipment-title', { resourceName: trailerName }),
            acceptButtonText: this.intl.t('trailer.actions.attach-equipment'),
            acceptButtonIcon: 'link',
            selectedEquipment: null,
            trailer,
            confirm: async (modal) => {
                const equipment = modal.getOption('selectedEquipment');

                if (!equipment) {
                    return this.notifications.warning(this.intl.t('trailer.prompts.select-equipment-warning'));
                }

                modal.startLoading();

                try {
                    await this.fetch.post(`trailers/${trailer.id}/attach-equipment`, { equipment: equipment.id });
                    await trailer.reload?.();
                    this.notifications.success(this.intl.t('trailer.prompts.attach-equipment-success', { equipmentName: equipment.name, resourceName: trailerName }));
                    modal.done();
                    this.refresh();
                    options.callback?.(trailer, equipment);
                } catch (error) {
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
            ...options,
        });
    }

    @action detachEquipment(trailer, equipment, options = {}) {
        const trailerName = this.trailerName(trailer);
        const equipmentName = equipment?.name ?? equipment?.public_id ?? this.intl.t('resource.equipment');

        return this.modalsManager.confirm({
            title: this.intl.t('trailer.prompts.detach-equipment-title', { equipmentName }),
            body: this.intl.t('trailer.prompts.detach-equipment-body', { equipmentName, resourceName: trailerName }),
            acceptButtonText: this.intl.t('trailer.actions.detach-equipment'),
            acceptButtonIcon: 'unlink',
            acceptButtonScheme: 'danger',
            confirm: async (modal) => {
                modal.startLoading();

                try {
                    await this.fetch.post(`trailers/${trailer.id}/detach-equipment`, { equipment: equipment.id });
                    await trailer.reload?.();
                    this.notifications.success(this.intl.t('trailer.prompts.detach-equipment-success', { equipmentName, resourceName: trailerName }));
                    modal.done();
                    this.refresh();
                    options.callback?.(trailer, equipment);
                } catch (error) {
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
            ...options,
        });
    }

    @action locate(trailer, options = {}) {
        const { latitude, longitude, location } = trailer;
        const trailerName = this.trailerName(trailer);

        if (!trailer.hasValidCoordinates) {
            return this.notifications.warning(this.intl.t('trailer.prompts.no-location-warning', { trailerName }));
        }

        this.modalsManager.show('modals/point-map', {
            title: this.intl.t('common.resource-location', { resource: trailerName }),
            acceptButtonText: this.intl.t('common.done'),
            hideDeclineButton: true,
            resource: trailer,
            popupText: `${trailerName} (${trailer.public_id})`,
            icon: leafletIcon({
                iconUrl: trailer.photo_url ?? config?.defaultValues?.vehicleAvatar,
                iconSize: [40, 40],
            }),
            latitude,
            longitude,
            location,
            ...options,
        });
    }

    @action async scheduleMaintenance(trailer, options = {}, saveOptions = {}) {
        trailer = await this.resolveTrailerResource(trailer);

        return this.maintenanceScheduleActions.modal.create({ subject: trailer }, options, saveOptions);
    }

    @action async createWorkOrder(trailer, options = {}, saveOptions = {}) {
        trailer = await this.resolveTrailerResource(trailer);

        return this.workOrderActions.modal.create({ target: trailer }, options, saveOptions);
    }

    @action async logMaintenance(trailer, options = {}, saveOptions = {}) {
        trailer = await this.resolveTrailerResource(trailer);

        return this.maintenanceActions.modal.create({ maintainable: trailer }, options, saveOptions);
    }
}
