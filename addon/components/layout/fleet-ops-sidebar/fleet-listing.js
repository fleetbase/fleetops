import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class LayoutFleetOpsSidebarFleetListingComponent extends Component {
    @service store;
    @service universe;
    @service vehicleActions;
    @service leafletMapManager;
    @service hostRouter;
    @service abilities;
    @service notifications;
    @service intl;
    @tracked fleets = [];

    get dropdownButtonActions() {
        return [
            {
                label: this.intl.t('common.view-resource-details', { resource: this.intl.t('resource.vehicle') }),
                onClick: (vehicle) => {
                    this.vehicleActions.panel.view(vehicle);
                },
            },
            {
                label: this.intl.t('common.edit-resource-details', { resource: this.intl.t('resource.vehicle') }),
                onClick: (vehicle) => {
                    this.vehicleActions.panel.edit(vehicle, { useDefaultSaveTask: true });
                },
            },
            {
                label: this.intl.t('vehicle.actions.locate-vehicle'),
                onClick: (vehicle) => {
                    // If currently on the operations dashboard focus driver on the map
                    if (typeof this.hostRouter.currentRouteName === 'string' && this.hostRouter.currentRouteName.startsWith('console.fleet-ops.operations.orders')) {
                        return this.onVehicleClicked(vehicle);
                    }

                    this.vehicleActions.locate(vehicle);
                },
            },
            {
                separator: true,
            },
            {
                label: this.intl.t('common.delete-resource', { resource: this.intl.t('resource.vehicle') }),
                onClick: (vehicle) => {
                    this.vehicleActions.delete(vehicle);
                },
            },
        ];
    }

    constructor() {
        super(...arguments);
        this.fetchFleets.perform();
        this.listenForChanges();
    }

    @action transitionToRoute(toggleApiContext) {
        if (typeof this.args.route === 'string') {
            if (typeof this.hostRouter.currentRouteName === 'string' && this.hostRouter.currentRouteName.startsWith('console.fleet-ops.management.fleets.index')) {
                if (typeof toggleApiContext.toggle === 'function') {
                    toggleApiContext.toggle();
                }
            }

            this.hostRouter.transitionTo(this.args.route);
        }
    }

    @action calculateDropdownItemPosition(trigger) {
        let { top, left, width } = trigger.getBoundingClientRect();
        let style = {
            left: 11 + left + width,
            top: top + 2,
        };

        return { style };
    }

    /* eslint-disable no-empty */
    @action async onVehicleClicked(vehicle) {
        try {
            await this.hostRouter.transitionTo('console.fleet-ops.operations.orders.index', { queryParams: { layout: 'map' } });
        } catch {}

        if (this.leafletMapManager._livemap?.isReady()) {
            this.focusVehicleOnMap(vehicle);
        } else {
            this.universe.one('fleet-ops.live-map.on-loaded', () => {
                this.focusVehicleOnMap(vehicle);
            });
        }

        if (typeof this.args.onFocusVehicle === 'function') {
            this.args.onFocusVehicle(vehicle);
        }
    }

    @action async focusVehicleOnMap(vehicle) {
        await this.leafletMapManager.ensureInteractive({ timeoutMs: 8000 });
        this.leafletMapManager.flyToRecordLayer(vehicle, 16, {
            paddingBottomRight: [300, 200],
            moveend: () => {
                this.vehicleActions.panel.view(vehicle, { closeOnTransition: true });
            },
        });
    }

    @task *fetchFleets() {
        if (this.abilities.cannot('fleet-ops list fleet')) return;

        try {
            this.fleets = yield this.store.query('fleet', { with: ['vehicles', 'subfleets'], parents_only: true });
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    listenForChanges() {
        // when a vehicle is assigned/ or unassigned reload
        this.universe.on('fleet-ops.fleet.vehicle_assigned', () => {
            this.fetchFleets.perform();
        });

        // when a vehicle is assigned/ or unassigned reload
        this.universe.on('fleet-ops.fleet.vehicle_unassigned', () => {
            this.fetchFleets.perform();
        });

        // when a driver is assigned/ or unassigned reload
        this.universe.on('fleet-ops.fleet.driver_assigned', () => {
            this.fetchFleets.perform();
        });

        // when a driver is assigned/ or unassigned reload
        this.universe.on('fleet-ops.fleet.driver_unassigned', () => {
            this.fetchFleets.perform();
        });
    }
}
