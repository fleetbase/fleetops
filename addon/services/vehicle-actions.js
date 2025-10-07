import ResourceActionService from '@fleetbase/ember-core/services/resource-action';
import leafletIcon from '@fleetbase/ember-core/utils/leaflet-icon';
import config from 'ember-get-config';
import { action } from '@ember/object';

export default class VehicleActionsService extends ResourceActionService {
    constructor() {
        super(...arguments);
        this.initialize('vehicle', { status: 'active' });
    }

    transition = {
        view: (vehicle) => this.transitionTo('management.vehicles.index.details', vehicle),
        edit: (vehicle) => this.transitionTo('management.vehicles.index.edit', vehicle),
        create: () => this.transitionTo('management.vehicles.index.new'),
    };

    panel = {
        create: (attributes = {}, options = {}) => {
            const vehicle = this.createNewInstance(attributes);
            return this.resourceContextPanel.open({
                content: 'vehicle/form',
                title: 'Create a new vehicle',

                saveOptions: {
                    callback: this.refresh,
                },
                vehicle,
                ...options,
            });
        },
        edit: (vehicle, options = {}) => {
            return this.resourceContextPanel.open({
                content: 'vehicle/form',
                title: `Edit: ${vehicle.name}`,
                actionButtons: [
                    {
                        icon: 'eye',
                        fn: async () => {
                            await this.resourceContextPanel.closeAll();
                            this.panel.view(vehicle);
                        },
                    },
                ],

                vehicle,
                ...options,
            });
        },
        view: (vehicle, options = {}) => {
            return this.resourceContextPanel.open({
                vehicle,
                header: 'vehicle/panel-header',
                actionButtons: [
                    {
                        icon: 'pencil',
                        fn: async () => {
                            await this.resourceContextPanel.closeAll();
                            this.panel.edit(vehicle);
                        },
                    },
                ],
                tabs: [
                    {
                        label: 'Overview',
                        component: 'vehicle/details',
                    },
                ],
                ...options,
            });
        },
    };

    modal = {
        create: (attributes = {}, options = {}, saveOptions = {}) => {
            const vehicle = this.createNewInstance(attributes);
            return this.modalsManager.show('modals/resource', {
                resource: vehicle,
                title: 'Create a new vehicle',
                acceptButtonText: 'Create Vehicle',
                component: 'vehicle/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', vehicle, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        edit: (vehicle, options = {}, saveOptions = {}) => {
            return this.modalsManager.show('modals/resource', {
                resource: vehicle,
                title: `Edit: ${vehicle.name}`,
                acceptButtonText: 'Save Changes',
                saveButtonIcon: 'save',
                component: 'vehicle/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', vehicle, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        view: (vehicle, options = {}) => {
            return this.modalsManager.show('modals/resource', {
                resource: vehicle,
                title: vehicle.name,
                component: 'vehicle/details',
                ...options,
            });
        },
    };

    @action locate(vehicle, options = {}) {
        const { latitude, longitude, location } = vehicle;

        this.modalsManager.show('modals/point-map', {
            title: this.intl.t('fleet-ops.management.vehicles.index.locate-title', { vehicleName: vehicle.displayName }),
            acceptButtonText: 'Done',
            hideDeclineButton: true,
            resource: vehicle,
            popupText: `${vehicle.displayName} (${vehicle.public_id})`,
            tooltip: vehicle.positionString,
            icon: leafletIcon({
                iconUrl: vehicle.avatar ?? config?.defaultValues?.vehicleAvatar,
                iconSize: [40, 40],
            }),
            latitude,
            longitude,
            location,
            ...options,
        });
    }
}
