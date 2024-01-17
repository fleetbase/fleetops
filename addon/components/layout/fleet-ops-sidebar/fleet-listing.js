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

    @action onClickVehicle(vehicle) {
        // Transition to dashboard/map display
        return this.hostRouter.transitionTo('console.fleet-ops.operations.orders.index', { queryParams: { layout: 'map' } }).then((transition) => {
            // Focus vehicle on live map
            this.focusVehicleOnLiveMap(vehicle);
            // Fire callback
            if (typeof this.args.onFocusVehicle === 'function') {
                this.args.onFocusVehicle(vehicle);
            }
        });
    }

    @action focusVehicleOnLiveMap(vehicle) {
        const liveMap = this.universe.get('component:fleet-ops:live-map');

        if (liveMap) {
            if (liveMap.contextPanel) {
                liveMap.contextPanel.clear();
            }

            liveMap.showAll();
            liveMap.focusLayerByRecord(vehicle, 16, {
                onAfterFocusWithRecord: function () {
                    later(
                        this,
                        () => {
                            liveMap.onVehicleClicked(vehicle);
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

    resolveFleetVehicles(fleet) {
        fleet.vehicles = isArray(fleet.vehicles) ? fleet.vehicles.toArray() : [];
        return fleet;
    }
}
