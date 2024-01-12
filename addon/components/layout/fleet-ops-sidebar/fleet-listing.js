import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { isArray } from '@ember/array';
import { later } from '@ember/runloop';

export default class LayoutFleetOpsSidebarFleetListingComponent extends Component {
    @service store;
    @service notifications;
    @service universe;
    @service hostRouter;
    @tracked fleets = [];
    @tracked isLoading = false;

    constructor() {
        super(...arguments);
        this.loadFleetsWithVehicles();
        this.listenForFleetChanges();
    }

    @action transitionHome() {
        return this.hostRouter.transitionTo('console.fleet-ops.operations.orders.index', { queryParams: { layout: 'map' } });
    }

    @action async onFocusVehicle(vehicle) {
        const { onFocusVehicle } = this.args;
        // const { currentRouteName } = this.hostRouter;
        // const isOperationsRouteActive = typeof currentRouteName === 'string' && currentRouteName.includes('fleet-ops.operations.orders');

        // transition to dashboard/map display
        try {
            await this.hostRouter.transitionTo('console.fleet-ops.operations.orders.index', { queryParams: { layout: 'map' } });
        } catch (error) {
            // silent
        }

        // focus vehicle on live map
        this.focusVehicleOnLiveMap(vehicle);

        if (typeof onFocusVehicle === 'function') {
            onFocusVehicle(vehicle);
        }
    }

    @action focusVehicleOnLiveMap(vehicle) {
        const FleetOpsLiveMap = this.universe.get('FleetOpsLiveMap');

        if (FleetOpsLiveMap) {
            if (FleetOpsLiveMap.contextPanel) {
                FleetOpsLiveMap.contextPanel.clear();
            }

            FleetOpsLiveMap.showAll();
            FleetOpsLiveMap.focusLayerByRecord(vehicle, 16, {
                onAfterFocusWithRecord: function () {
                    later(
                        this,
                        () => {
                            FleetOpsLiveMap.onVehicleClicked(vehicle);
                        },
                        600 * 2
                    );
                },
            });
        }
    }

    listenForFleetChanges() {
        // when a vehicle is assigned/ or unassigned reload
        this.universe.on('fleet.vehicle.assigned', () => {
            this.loadFleetsWithVehicles();
        });

        // when a vehicle is assigned/ or unassigned reload
        this.universe.on('fleet.vehicle.unassigned', () => {
            this.loadFleetsWithVehicles();
        });
    }

    loadFleetsWithVehicles() {
        this.isLoading = true;
        this.store
            .query('fleet', { with: ['vehicles', 'subfleets'], parents_only: true })
            .then((fleets) => {
                this.fleets = fleets.toArray().map(this.resolveFleetVehicles);
            })
            .catch((error) => {
                this.notifications.serverError(error);
            })
            .finally(() => {
                this.isLoading = false;
            });
    }

    @action resolveFleetVehicles(fleet) {
        fleet.vehicles = isArray(fleet.vehicles) ? fleet.vehicles.toArray() : [];
        return fleet;
    }
}
