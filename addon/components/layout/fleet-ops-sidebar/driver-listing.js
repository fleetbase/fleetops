import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class LayoutFleetOpsSidebarDriverListingComponent extends Component {
    @service driverActions;
    @service leafletMapManager;
    @service store;
    @service universe;
    @service hostRouter;
    @service abilities;
    @service notifications;
    @service intl;
    @tracked drivers = [];
    @tracked displayPanelDropdown = true;

    get panelDropdownButtonActions() {
        return [
            {
                label: this.intl.t('common.create-a-new-resource', { resource: this.intl.t('resource.driver') }),
                disabled: this.abilities.cannot('fleet-ops create driver'),
                onClick: () => {
                    this.driverActions.panel.create();
                },
            },
        ];
    }

    get dropdownButtonActions() {
        return [
            {
                label: this.intl.t('common.view-resource-details', { resource: this.intl.t('resource.driver') }),
                disabled: this.abilities.cannot('fleet-ops view driver'),
                onClick: (driver) => {
                    this.driverActions.panel.view(driver);
                },
            },
            {
                label: this.intl.t('common.edit-resource-details', { resource: this.intl.t('resource.driver') }),
                disabled: this.abilities.cannot('fleet-ops update driver'),
                onClick: (driver) => {
                    this.driverActions.panel.edit(driver, { useDefaultSaveTask: true });
                },
            },
            {
                separator: true,
            },
            {
                label: this.intl.t('driver.actions.assign-order'),
                disabled: this.abilities.cannot('fleet-ops assign-order-for driver'),
                onClick: (driver) => {
                    this.driverActions.assignOrder(driver);
                },
            },
            {
                label: this.intl.t('driver.actions.assign-vehicle'),
                disabled: this.abilities.cannot('fleet-ops assign-vehicle-for driver'),
                onClick: (driver) => {
                    this.driverActions.assignVehicle(driver);
                },
            },
            {
                label: this.intl.t('driver.actions.locate-driver'),
                disabled: this.abilities.cannot('fleet-ops view driver'),
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
                label: this.intl.t('common.delete-resource', { resource: this.intl.t('resource.driver') }),
                disabled: this.abilities.cannot('fleet-ops delete driver'),
                onClick: (driver) => {
                    this.driverActions.delete(driver);
                },
            },
        ];
    }

    constructor() {
        super(...arguments);
        this.fetchDrivers.perform();
        this.universe.on('fleet-ops.driver.saved', () => {
            this.fetchDrivers.perform();
        });
    }

    @action calculateDropdownPosition(trigger, content) {
        let { top, left, width, height } = trigger.getBoundingClientRect();
        let { height: contentHeight } = content.getBoundingClientRect();
        let style = {
            left: 3 + left + width,
            top: 29 + top + window.pageYOffset + height / 2 - contentHeight / 2,
        };

        return { style };
    }

    @action calculateDropdownItemPosition(trigger) {
        let { top, left, width } = trigger.getBoundingClientRect();
        let style = {
            left: 11 + left + width,
            top: top + 2,
        };

        return { style };
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

    /* eslint-disable no-empty */
    @action async onDriverClicked(driver) {
        try {
            await this.hostRouter.transitionTo('console.fleet-ops.operations.orders.index', { queryParams: { layout: 'map' } });
        } catch {}

        if (this.leafletMapManager._livemap?.isReady()) {
            this.focusDriverOnMap(driver);
        } else {
            this.universe.one('fleet-ops.live-map.on-loaded', () => {
                this.focusDriverOnMap(driver);
            });
        }

        if (typeof this.args.onFocusDriver === 'function') {
            this.args.onFocusDriver(driver);
        }
    }

    @action async focusDriverOnMap(driver) {
        await this.leafletMapManager.ensureInteractive({ timeoutMs: 8000 });
        this.leafletMapManager.flyToRecordLayer(driver, 16, {
            paddingBottomRight: [300, 200],
            moveend: () => {
                this.driverActions.panel.view(driver, { closeOnTransition: true });
            },
        });
    }

    @task *fetchDrivers() {
        try {
            this.drivers = yield this.store.query('driver', { limit: 20, without: ['vendor'] });
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
}
