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
    @tracked universeMenuPanels = [];

    constructor() {
        super(...arguments);
        this.createMenuItemsFromUniverseRegistry();
        this.createMenuPanels();
    }

    createMenuItemsFromUniverseRegistry() {
        this.universeMenuItems = this.universe.getMenuItemsFromRegistry('engine:fleet-ops');
        this.universeMenuPanels = this.universe.getMenuPanelsFromRegistry('engine:fleet-ops');
    }

    /**
     * Initialize menu panels with visibility settings.
     */
    createMenuPanels() {
        const operationsItems = [
            { title: this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.dashboard'), icon: 'home', route: 'operations.orders' },
            { title: this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.service-rates'), icon: 'file-invoice-dollar', route: 'operations.service-rates' },
            { title: this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.scheduler'), icon: 'calendar-day', route: 'operations.scheduler' },
            { title: this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.order-config'), icon: 'diagram-project', route: 'operations.order-config' },
        ];

        const resourcesItems = [
            {
                title: this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.drivers'),
                icon: 'id-card',
                route: 'management.drivers',
                renderComponentInPlace: true,
                component: DriverListingComponent,
            },
            { title: this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.vehicles'), icon: 'truck', route: 'management.vehicles' },
            {
                title: this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.fleets'),
                icon: 'user-group',
                route: 'management.fleets',
                renderComponentInPlace: true,
                component: FleetListingComponent,
            },
            { title: this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.vendors'), icon: 'warehouse', route: 'management.vendors' },
            { title: this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.contacts'), icon: 'address-book', route: 'management.contacts' },
            { title: this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.places'), icon: 'location-dot', route: 'management.places' },
            { title: this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.fuel-reports'), icon: 'gas-pump', route: 'management.fuel-reports' },
            { title: this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.issues'), icon: 'triangle-exclamation', route: 'management.issues' },
        ];

        const settingsItems = [{ title: this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.navigator-app'), icon: 'location-arrow', route: 'settings.navigator-app' }];

        const createPanel = (title, routePrefix, items = []) => ({
            title,
            open: true,
            visible: this.isPanelVisible(routePrefix),
            items: items
                .map((item) => ({
                    ...item,
                    visible: this.isItemVisible(item.route),
                }))
                .filter((item) => item.visible),
        });

        this.menuPanels = [
            createPanel(this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.operations'), 'operations', operationsItems),
            createPanel(this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.resources'), 'management', resourcesItems),
            createPanel(this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.settings'), 'settings', settingsItems),
        ].filter((panel) => {
            const isVisible = panel.visible && panel.items.length > 0;
            return isVisible;
        });
    }

    /**
     * Check if a panel should be visible based on visibility settings.
     *
     * @param {string} routePrefix - The route prefix to check for visibility.
     * @returns {boolean} - Whether the panel should be visible.
     */
    isPanelVisible(routePrefix) {
        return this.isVisible(routePrefix, false);
    }

    /**
     * Check if a menu item should be visible based on visibility settings.
     *
     * @param {string} routePrefix - The route prefix to check for visibility.
     * @returns {boolean} - Whether the menu item should be visible.
     */
    isItemVisible(routePrefix) {
        return this.isVisible(routePrefix, true);
    }

    /**
     * Utility function to check visibility based on route prefix.
     *
     * @param {string} routePrefix - The route prefix to check for visibility.
     * @param {boolean} exactMatch - Whether to match the route exactly or just the prefix.
     * @returns {boolean} - Whether the item should be visible.
     */
    isVisible(routePrefix, exactMatch) {
        const { visibilitySettings } = this.args;

        if (!isArray(visibilitySettings)) {
            return true;
        }

        // Check if the route exists in the settings
        const routeExists = visibilitySettings.some((visibilityControl) => (exactMatch ? visibilityControl.route === routePrefix : visibilityControl.route.startsWith(routePrefix)));

        // If the route doesn't exist in the settings, return true (visible by default)
        if (!routeExists) {
            return true;
        }

        return visibilitySettings.some((visibilityControl) => {
            const match = exactMatch ? visibilityControl.route === routePrefix : visibilityControl.route.startsWith(routePrefix);
            return match && visibilityControl.visible;
        });
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
