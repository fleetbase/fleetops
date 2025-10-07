import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import FleetListingComponent from './fleet-ops-sidebar/fleet-listing';
import DriverListingComponent from './fleet-ops-sidebar/driver-listing';

export default class LayoutFleetOpsSidebarComponent extends Component {
    @service universe;
    @service store;
    @service intl;
    @service abilities;
    @service appCache;
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

    createMenuPanels() {
        const operationsItems = [
            {
                intl: 'fleet-ops.component.layout.fleet-ops-sidebar.dashboard',
                title: this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.dashboard'),
                icon: 'home',
                route: 'operations.orders',
                permission: 'fleet-ops list order',
                visible: this.abilities.can('fleet-ops see order'),
            },
            {
                intl: 'fleet-ops.component.layout.fleet-ops-sidebar.service-rates',
                title: this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.service-rates'),
                icon: 'file-invoice-dollar',
                route: 'operations.service-rates',
                permission: 'fleet-ops list service-rate',
                visible: this.abilities.can('fleet-ops see service-rate'),
            },
            {
                intl: 'fleet-ops.component.layout.fleet-ops-sidebar.scheduler',
                title: this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.scheduler'),
                icon: 'calendar-day',
                route: 'operations.scheduler',
                permission: 'fleet-ops list order',
                visible: this.abilities.can('fleet-ops see order'),
            },
            {
                intl: 'fleet-ops.component.layout.fleet-ops-sidebar.order-config',
                title: this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.order-config'),
                icon: 'diagram-project',
                route: 'operations.order-config',
                permission: 'fleet-ops list order-config',
                visible: this.abilities.can('fleet-ops see order-config'),
            },
        ];

        const resourcesItems = [
            {
                intl: 'fleet-ops.component.layout.fleet-ops-sidebar.drivers',
                title: this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.drivers'),
                icon: 'id-card',
                route: 'management.drivers',
                renderComponentInPlace: true,
                component: DriverListingComponent,
                permission: 'fleet-ops list driver',
                visible: this.abilities.can('fleet-ops see driver'),
            },
            {
                intl: 'fleet-ops.component.layout.fleet-ops-sidebar.vehicles',
                title: this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.vehicles'),
                icon: 'truck',
                route: 'management.vehicles',
                permission: 'fleet-ops list vehicle',
                visible: this.abilities.can('fleet-ops see vehicle'),
            },
            {
                intl: 'fleet-ops.component.layout.fleet-ops-sidebar.fleets',
                title: this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.fleets'),
                icon: 'user-group',
                route: 'management.fleets',
                renderComponentInPlace: true,
                component: FleetListingComponent,
                permission: 'fleet-ops list fleet',
                visible: this.abilities.can('fleet-ops see fleet'),
            },
            {
                intl: 'fleet-ops.component.layout.fleet-ops-sidebar.vendors',
                title: this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.vendors'),
                icon: 'warehouse',
                route: 'management.vendors',
                permission: 'fleet-ops list vendor',
                visible: this.abilities.can('fleet-ops see vendor'),
            },
            {
                intl: 'fleet-ops.component.layout.fleet-ops-sidebar.contacts',
                title: this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.contacts'),
                icon: 'address-book',
                route: 'management.contacts',
                permission: 'fleet-ops list contact',
                visible: this.abilities.can('fleet-ops see contact'),
            },
            {
                intl: 'fleet-ops.component.layout.fleet-ops-sidebar.places',
                title: this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.places'),
                icon: 'location-dot',
                route: 'management.places',
                permission: 'fleet-ops list place',
                visible: this.abilities.can('fleet-ops see place'),
            },
            {
                intl: 'fleet-ops.component.layout.fleet-ops-sidebar.fuel-reports',
                title: this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.fuel-reports'),
                icon: 'gas-pump',
                route: 'management.fuel-reports',
                permission: 'fleet-ops list fuel-report',
                visible: this.abilities.can('fleet-ops see fuel-report'),
            },
            {
                intl: 'fleet-ops.component.layout.fleet-ops-sidebar.issues',
                title: this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.issues'),
                icon: 'triangle-exclamation',
                route: 'management.issues',
                permission: 'fleet-ops list issue',
                visible: this.abilities.can('fleet-ops see issue'),
            },
        ];

        const connectivityItems = [
            {
                intl: 'fleet-ops.component.layout.fleet-ops-sidebar.telematics',
                title: this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.telematics'),
                icon: 'satellite-dish',
                route: 'connectivity.telematics',
                permission: 'fleet-ops list telematic',
                visible: this.abilities.can('fleet-ops see telematic'),
            },
            {
                intl: 'fleet-ops.component.layout.fleet-ops-sidebar.devices',
                title: this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.devices'),
                icon: 'hard-drive',
                route: 'connectivity.devices',
                permission: 'fleet-ops list device',
                visible: this.abilities.can('fleet-ops see device'),
            },
            {
                intl: 'fleet-ops.component.layout.fleet-ops-sidebar.sensors',
                title: this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.sensors'),
                icon: 'temperature-full',
                route: 'connectivity.sensors',
                permission: 'fleet-ops list sensor',
                visible: this.abilities.can('fleet-ops see sensor'),
            },
            {
                intl: 'fleet-ops.component.layout.fleet-ops-sidebar.events',
                title: this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.events'),
                icon: 'stream',
                route: 'connectivity.events',
                permission: 'fleet-ops list device-event',
                visible: this.abilities.can('fleet-ops see device-event'),
            },
            {
                intl: 'fleet-ops.component.layout.fleet-ops-sidebar.tracking',
                title: this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.tracking'),
                icon: 'map-marked-alt',
                route: 'connectivity.tracking',
                permission: 'fleet-ops list device',
                visible: this.abilities.can('fleet-ops see device'),
            },
        ];

        const maintenanceItems = [
            {
                intl: 'fleet-ops.component.layout.fleet-ops-sidebar.work-orders',
                title: this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.work-orders'),
                icon: 'clipboard-list',
                route: 'maintenance.work-orders',
                permission: 'fleet-ops list work-order',
                visible: this.abilities.can('fleet-ops see work-order'),
            },
            {
                intl: 'fleet-ops.component.layout.fleet-ops-sidebar.equipment',
                title: this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.equipment'),
                icon: 'trailer',
                route: 'maintenance.equipment',
                permission: 'fleet-ops list equipment',
                visible: this.abilities.can('fleet-ops see equipment'),
            },
            {
                intl: 'fleet-ops.component.layout.fleet-ops-sidebar.parts',
                title: this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.parts'),
                icon: 'cog',
                route: 'maintenance.parts',
                permission: 'fleet-ops list part',
                visible: this.abilities.can('fleet-ops see part'),
            },
        ];

        const analyticsItems = [
            {
                intl: 'fleet-ops.component.layout.fleet-ops-sidebar.reports',
                title: this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.reports'),
                icon: 'file-import',
                route: 'analytics.reports',
                permission: 'iam list report',
                visible: this.abilities.can('fleet-ops see report'),
            },
        ];

        const settingsItems = [
            {
                intl: 'fleet-ops.component.layout.fleet-ops-sidebar.navigator-app',
                title: this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.navigator-app'),
                icon: 'location-arrow',
                route: 'settings.navigator-app',
                permission: 'fleet-ops view navigator-settings',
                visible: this.abilities.can('fleet-ops see navigator-settings'),
            },
            {
                intl: 'fleet-ops.component.layout.fleet-ops-sidebar.payments',
                title: this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.payments'),
                icon: 'cash-register',
                route: 'settings.payments',
                permission: 'fleet-ops view payments',
                visible: this.abilities.can('fleet-ops see payments'),
            },
            {
                intl: 'fleet-ops.component.layout.fleet-ops-sidebar.notifications',
                title: this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.notifications'),
                icon: 'bell',
                route: 'settings.notifications',
                permission: 'fleet-ops view notification-settings',
                visible: this.abilities.can('fleet-ops see notification-settings'),
            },
            {
                intl: 'fleet-ops.component.layout.fleet-ops-sidebar.routing',
                title: this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.routing'),
                icon: 'route',
                route: 'settings.routing',
                permission: 'fleet-ops view routing-settings',
                visible: this.abilities.can('fleet-ops see routing-settings'),
            },
            {
                intl: 'fleet-ops.component.layout.fleet-ops-sidebar.custom-fields',
                title: this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.custom-fields'),
                icon: 'rectangle-list',
                route: 'settings.custom-fields',
                permission: 'fleet-ops view custom-field',
                visible: this.abilities.can('fleet-ops see custom-field'),
            },
        ];

        const createPanel = (intl, routePrefix, items = [], options = {}) => ({
            intl,
            title: this.intl.t(intl),
            routePrefix,
            open: options.open ?? true,
            items,
            onToggle: options.onToggle,
        });

        this.menuPanels = this.removeEmptyMenuPanels([
            createPanel('fleet-ops.component.layout.fleet-ops-sidebar.operations', 'operations', operationsItems, {
                open: this.appCache.get('fleet-ops:sidebar:operations:open', true),
                onToggle: (open) => this.appCache.set('fleet-ops:sidebar:operations:open', open),
            }),
            createPanel('fleet-ops.component.layout.fleet-ops-sidebar.resources', 'management', resourcesItems, {
                open: this.appCache.get('fleet-ops:sidebar:management:open', true),
                onToggle: (open) => this.appCache.set('fleet-ops:sidebar:management:open', open),
            }),
            createPanel('fleet-ops.component.layout.fleet-ops-sidebar.maintenance', 'maintenance', maintenanceItems, {
                open: this.appCache.get('fleet-ops:sidebar:maintenance:open', false),
                onToggle: (open) => this.appCache.set('fleet-ops:sidebar:maintenance:open', open),
            }),
            createPanel('fleet-ops.component.layout.fleet-ops-sidebar.connectivity', 'connectivity', connectivityItems, {
                open: this.appCache.get('fleet-ops:sidebar:connectivity:open', false),
                onToggle: (open) => this.appCache.set('fleet-ops:sidebar:connectivity:open', open),
            }),
            createPanel('fleet-ops.component.layout.fleet-ops-sidebar.analytics', 'analytics', analyticsItems, {
                open: this.appCache.get('fleet-ops:sidebar:analytics:open', false),
                onToggle: (open) => this.appCache.set('fleet-ops:sidebar:analytics:open', open),
            }),
            createPanel('fleet-ops.component.layout.fleet-ops-sidebar.settings', 'settings', settingsItems, {
                open: this.appCache.get('fleet-ops:sidebar:settings:open', true),
                onToggle: (open) => this.appCache.set('fleet-ops:sidebar:settings:open', open),
            }),
        ]);
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
     * Filters menuPanels, leaving only menuPanels with visible items
     *
     * @param {Array} [menuPanels=[]]
     * @return {Array}
     * @memberof LayoutFleetOpsSidebarComponent
     */
    removeEmptyMenuPanels(menuPanels = []) {
        return menuPanels.filter((menuPanel) => {
            const visibleItems = menuPanel.items.filter((item) => item.visible);
            return visibleItems.length > 0;
        });
    }
}
