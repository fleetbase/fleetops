import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { later } from '@ember/runloop';
import { task } from 'ember-concurrency-decorators';

export default class LayoutFleetOpsSidebarDriverListingComponent extends Component {
    @service store;
    @service universe;
    @service contextPanel;
    @service driverActions;
    @service hostRouter;
    @tracked drivers = [];

    constructor() {
        super(...arguments);
        this.fetchDrivers.perform();
    }

    displayPanelDropdown = true;
    panelDropdownButtonActions = [
        {
            label: 'Create new driver...',
            onClick: () => {
                const driver = this.store.createRecord('driver');
                this.contextPanel.focus(driver, 'editing');
            },
        },
    ];

    dropdownButtonActions = [
        {
            label: 'View driver details...',
            onClick: (driver) => {
                this.contextPanel.focus(driver);
            },
        },
        {
            label: 'Edit driver details...',
            onClick: (driver) => {
                this.contextPanel.focus(driver, 'editing');
            },
        },
        {
            separator: true,
        },
        {
            label: 'Assign order to driver...',
            onClick: (driver) => {
                this.driverActions.assignOrder(driver);
            },
        },
        {
            label: 'Assign vehicle to driver...',
            onClick: (driver) => {
                this.driverActions.assignVehicle(driver);
            },
        },
        {
            label: 'Locate driver on map...',
            onClick: (driver) => {
                // If currently on the operations dashboard focus driver on the map
                if (typeof this.hostRouter.currentRouteName === 'string' && this.hostRouter.currentRouteName.startsWith('console.fleet-ops.operations.orders')) {
                    return this.onDriverClicked(driver);
                }

                this.driverActions.locate(driver);
            },
        },
        {
            separator: true,
        },
        {
            label: 'Delete driver...',
            onClick: (driver) => {
                this.driverActions.delete(driver);
            },
        },
    ];

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

    @action onDriverClicked(driver) {
        // Transition to dashboard/map display
        return this.hostRouter.transitionTo('console.fleet-ops.operations.orders.index', { queryParams: { layout: 'map' } }).then(() => {
            // Focus vehicle on live map
            this.focusDriverOnMap(driver);

            // Fire callback
            if (typeof this.args.onFocusDriver === 'function') {
                this.args.onFocusDriver(driver);
            }
        });
    }

    focusDriverOnMap(driver) {
        const liveMap = this.universe.get('component:fleet-ops:live-map');

        if (liveMap) {
            if (liveMap.contextPanel) {
                liveMap.contextPanel.clear();
            }

            liveMap.showAll();
            liveMap.focusLayerByRecord(driver, 16, {
                onAfterFocusWithRecord: function () {
                    later(
                        this,
                        () => {
                            liveMap.onDriverClicked(driver);
                        },
                        1200
                    );
                },
            });
        }
    }

    @task *fetchDrivers() {
        this.drivers = yield this.store.query('driver', { limit: 20 });
    }
}
