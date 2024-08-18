import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { isArray } from '@ember/array';
import FleetListingComponent from './fleet-ops-sidebar/fleet-listing';
import DriverListingComponent from './fleet-ops-sidebar/driver-listing';

/**
 * LayoutFleetOpsSidebarComponent
 *
 * This component manages the sidebar layout for Fleet Ops, including visibility and actions.
 */
export default class LayoutFleetOpsSidebarComponent extends Component {
    @service universe;
    @service contextPanel;
    @service store;
    @service intl;
    @tracked routePrefix = 'console.fleet-ops.';
    @tracked menuPanels = [];
    @tracked universeMenuItems = [];
    @tracked universeSettingsMenuItems = [];
    @tracked universeMenuPanels = [];

    constructor() {
        super(...arguments);
        this.createMenuItemsFromUniverseRegistry();
        this.createMenuPanels();
    }

    createMenuItemsFromUniverseRegistry() {
        const registeredMenuItems = this.universe.getMenuItemsFromRegistry('engine:fleet-ops');
        this.universeMenuPanels = this.universe.getMenuPanelsFromRegistry('engine:fleet-ops');
        this.universeMenuItems = registeredMenuItems.filter((menuItem) => menuItem.section === undefined);
        this.universeSettingsMenuItems = registeredMenuItems.filter((menuItem) => menuItem.section === 'settings');
    }

    /**
     * Initialize menu panels with visibility settings.
     */
    createMenuPanels() {
        const operationsItems = [
            {
                title: this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.dashboard'),
                icon: 'home',
                route: 'operations.orders',
                permission: 'fleet-ops list order',
            },
            {
                title: this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.service-rates'),
                icon: 'file-invoice-dollar',
                route: 'operations.service-rates',
                permission: 'fleet-ops list service-rate',
            },
            {
                title: this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.scheduler'),
                icon: 'calendar-day',
                route: 'operations.scheduler',
                permission: 'fleet-ops list order',
            },
            {
                title: this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.order-config'),
                icon: 'diagram-project',
                route: 'operations.order-config',
                permission: 'fleet-ops list order-config',
            },
        ];

        const resourcesItems = [
            {
                title: this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.drivers'),
                icon: 'id-card',
                route: 'management.drivers',
                renderComponentInPlace: true,
                component: DriverListingComponent,
                permission: 'fleet-ops list driver',
            },
            {
                title: this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.vehicles'),
                icon: 'truck',
                route: 'management.vehicles',
                permission: 'fleet-ops list vehicle',
            },
            {
                title: this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.fleets'),
                icon: 'user-group',
                route: 'management.fleets',
                renderComponentInPlace: true,
                component: FleetListingComponent,
                permission: 'fleet-ops list fleet',
            },
            {
                title: this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.vendors'),
                icon: 'warehouse',
                route: 'management.vendors',
                permission: 'fleet-ops list vendor',
            },
            {
                title: this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.contacts'),
                icon: 'address-book',
                route: 'management.contacts',
                permission: 'fleet-ops list contact',
            },
            {
                title: this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.places'),
                icon: 'location-dot',
                route: 'management.places',
                permission: 'fleet-ops list place',
            },
            {
                title: this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.fuel-reports'),
                icon: 'gas-pump',
                route: 'management.fuel-reports',
                permission: 'fleet-ops list fuel-report',
            },
            {
                title: this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.issues'),
                icon: 'triangle-exclamation',
                route: 'management.issues',
                permission: 'fleet-ops list issue',
            },
        ];

        const settingsItems = [
            {
                title: this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.navigator-app'),
                icon: 'location-arrow',
                route: 'settings.navigator-app',
            },
        ];

        const createPanel = (title, routePrefix, items = []) => ({
            title,
            routePrefix,
            open: true,
            items,
        });

        this.menuPanels = [
            createPanel(this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.operations'), 'operations', operationsItems),
            createPanel(this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.resources'), 'management', resourcesItems),
            createPanel(this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.settings'), 'settings', settingsItems),
        ];
    }

    /**
     * Action handler for creating an order.
     */
    @action onClickCreateOrder() {
        const { onClickCreateOrder } = this.args;

        if (typeof onClickCreateOrder === 'function') {
            onClickCreateOrder();
        }
    }

    /**
     * Action handler for opening settings.
     */
    @action onClickSettings() {
        const { onClickSettings } = this.args;

        if (typeof onClickSettings === 'function') {
            onClickSettings();
        }
    }
}
