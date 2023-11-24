import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { isArray } from '@ember/array';

/**
 * LayoutFleetOpsSidebarComponent
 *
 * This component manages the sidebar layout for Fleet Ops, including visibility and actions.
 */
export default class LayoutFleetOpsSidebarComponent extends Component {
    @service universe;
    @service contextPanel;
    @service store;
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
            { title: 'Dashboard', icon: 'home', route: 'operations.orders' },
            { title: 'Service Rates', icon: 'file-invoice-dollar', route: 'operations.service-rates' },
            { title: 'Scheduler', icon: 'calendar-day', route: 'operations.scheduler' },
        ];

        const resourcesItems = [
            { title: 'Drivers', icon: 'id-card', route: 'management.drivers' },
            { title: 'Vehicles', icon: 'truck', route: 'management.vehicles' },
            { title: 'Fleets', icon: 'user-group', route: 'management.fleets' },
            { title: 'Vendors', icon: 'warehouse', route: 'management.vendors' },
            { title: 'Contacts', icon: 'address-book', route: 'management.contacts' },
            { title: 'Places', icon: 'location-dot', route: 'management.places' },
            { title: 'Fuel Reports', icon: 'gas-pump', route: 'management.fuel-reports' },
            { title: 'Issues', icon: 'triangle-exclamation', route: 'management.issues' },
        ];

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

        this.menuPanels = [createPanel('Operations', 'operations', operationsItems), createPanel('Resources', 'management', resourcesItems)].filter((panel) => {
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
