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

    /* eslint-disable no-unused-vars */
    createMenuPanels() {
        const operationsItems = [
            {
                intl: 'menu.dashboard',
                title: this.intl.t('menu.dashboard'),
                icon: 'home',
                route: 'operations.orders',
                permission: 'fleet-ops list order',
                visible: this.abilities.can('fleet-ops see order'),
            },
            {
                intl: 'menu.service-rates',
                title: this.intl.t('menu.service-rates'),
                icon: 'file-invoice-dollar',
                route: 'operations.service-rates',
                permission: 'fleet-ops list service-rate',
                visible: this.abilities.can('fleet-ops see service-rate'),
            },
            {
                intl: 'menu.scheduler',
                title: this.intl.t('menu.scheduler'),
                icon: 'calendar-day',
                route: 'operations.scheduler',
                permission: 'fleet-ops list order',
                visible: this.abilities.can('fleet-ops see order'),
            },
            {
                intl: 'menu.order-config',
                title: this.intl.t('menu.order-config'),
                icon: 'diagram-project',
                route: 'operations.order-config',
                permission: 'fleet-ops list order-config',
                visible: this.abilities.can('fleet-ops see order-config'),
            },
        ];

        const resourcesItems = [
            {
                intl: 'menu.drivers',
                title: this.intl.t('menu.drivers'),
                icon: 'id-card',
                route: 'management.drivers',
                renderComponentInPlace: true,
                component: DriverListingComponent,
                permission: 'fleet-ops list driver',
                visible: this.abilities.can('fleet-ops see driver'),
            },
            {
                intl: 'menu.vehicles',
                title: this.intl.t('menu.vehicles'),
                icon: 'truck',
                route: 'management.vehicles',
                permission: 'fleet-ops list vehicle',
                visible: this.abilities.can('fleet-ops see vehicle'),
            },
            {
                intl: 'menu.fleets',
                title: this.intl.t('menu.fleets'),
                icon: 'user-group',
                route: 'management.fleets',
                renderComponentInPlace: true,
                component: FleetListingComponent,
                permission: 'fleet-ops list fleet',
                visible: this.abilities.can('fleet-ops see fleet'),
            },
            {
                intl: 'menu.vendors',
                title: this.intl.t('menu.vendors'),
                icon: 'warehouse',
                route: 'management.vendors',
                permission: 'fleet-ops list vendor',
                visible: this.abilities.can('fleet-ops see vendor'),
            },
            {
                intl: 'menu.contacts',
                title: this.intl.t('menu.contacts'),
                icon: 'address-book',
                route: 'management.contacts',
                permission: 'fleet-ops list contact',
                visible: this.abilities.can('fleet-ops see contact'),
            },
            {
                intl: 'menu.places',
                title: this.intl.t('menu.places'),
                icon: 'location-dot',
                route: 'management.places',
                permission: 'fleet-ops list place',
                visible: this.abilities.can('fleet-ops see place'),
            },
            {
                intl: 'menu.fuel-reports',
                title: this.intl.t('menu.fuel-reports'),
                icon: 'gas-pump',
                route: 'management.fuel-reports',
                permission: 'fleet-ops list fuel-report',
                visible: this.abilities.can('fleet-ops see fuel-report'),
            },
            {
                intl: 'menu.issues',
                title: this.intl.t('menu.issues'),
                icon: 'triangle-exclamation',
                route: 'management.issues',
                permission: 'fleet-ops list issue',
                visible: this.abilities.can('fleet-ops see issue'),
            },
        ];

        const connectivityItems = [
            {
                intl: 'menu.telematics',
                title: this.intl.t('menu.telematics'),
                icon: 'satellite-dish',
                route: 'connectivity.telematics',
                permission: 'fleet-ops list telematic',
                visible: this.abilities.can('fleet-ops see telematic'),
            },
            {
                intl: 'menu.devices',
                title: this.intl.t('menu.devices'),
                icon: 'hard-drive',
                route: 'connectivity.devices',
                permission: 'fleet-ops list device',
                visible: this.abilities.can('fleet-ops see device'),
            },
            {
                intl: 'menu.sensors',
                title: this.intl.t('menu.sensors'),
                icon: 'temperature-full',
                route: 'connectivity.sensors',
                permission: 'fleet-ops list sensor',
                visible: this.abilities.can('fleet-ops see sensor'),
            },
            {
                intl: 'menu.events',
                title: this.intl.t('menu.events'),
                icon: 'stream',
                route: 'connectivity.events',
                permission: 'fleet-ops list device-event',
                visible: this.abilities.can('fleet-ops see device-event'),
            },
            // {
            //     intl: 'menu.tracking',
            //     title: this.intl.t('menu.tracking'),
            //     icon: 'map-marked-alt',
            //     route: 'connectivity.tracking',
            //     permission: 'fleet-ops list device',
            //     visible: this.abilities.can('fleet-ops see device'),
            // },
        ];

        const maintenanceItems = [
            {
                intl: 'menu.work-orders',
                title: this.intl.t('menu.work-orders'),
                icon: 'clipboard-list',
                route: 'maintenance.work-orders',
                permission: 'fleet-ops list work-order',
                visible: this.abilities.can('fleet-ops see work-order'),
            },
            {
                intl: 'menu.equipment',
                title: this.intl.t('menu.equipment'),
                icon: 'trailer',
                route: 'maintenance.equipment',
                permission: 'fleet-ops list equipment',
                visible: this.abilities.can('fleet-ops see equipment'),
            },
            {
                intl: 'menu.parts',
                title: this.intl.t('menu.parts'),
                icon: 'cog',
                route: 'maintenance.parts',
                permission: 'fleet-ops list part',
                visible: this.abilities.can('fleet-ops see part'),
            },
        ];

        const analyticsItems = [
            {
                intl: 'menu.reports',
                title: this.intl.t('menu.reports'),
                icon: 'file-import',
                route: 'analytics.reports',
                permission: 'iam list report',
                visible: this.abilities.can('fleet-ops see report'),
            },
        ];

        const settingsItems = [
            {
                intl: 'menu.navigator-app',
                title: this.intl.t('menu.navigator-app'),
                icon: 'location-arrow',
                route: 'settings.navigator-app',
                permission: 'fleet-ops view navigator-settings',
                visible: this.abilities.can('fleet-ops see navigator-settings'),
            },
            {
                intl: 'menu.payments',
                title: this.intl.t('menu.payments'),
                icon: 'cash-register',
                route: 'settings.payments',
                permission: 'fleet-ops view payments',
                visible: this.abilities.can('fleet-ops see payments'),
            },
            {
                intl: 'menu.notifications',
                title: this.intl.t('menu.notifications'),
                icon: 'bell',
                route: 'settings.notifications',
                permission: 'fleet-ops view notification-settings',
                visible: this.abilities.can('fleet-ops see notification-settings'),
            },
            {
                intl: 'menu.routing',
                title: this.intl.t('menu.routing'),
                icon: 'route',
                route: 'settings.routing',
                permission: 'fleet-ops view routing-settings',
                visible: this.abilities.can('fleet-ops see routing-settings'),
            },
            {
                intl: 'menu.custom-fields',
                title: this.intl.t('menu.custom-fields'),
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
            createPanel('menu.operations', 'operations', operationsItems, {
                open: this.appCache.get('fleet-ops:sidebar:operations:open', true),
                onToggle: (open) => this.appCache.set('fleet-ops:sidebar:operations:open', open),
            }),
            createPanel('menu.resources', 'management', resourcesItems, {
                open: this.appCache.get('fleet-ops:sidebar:management:open', true),
                onToggle: (open) => this.appCache.set('fleet-ops:sidebar:management:open', open),
            }),
            // createPanel('menu.maintenance', 'maintenance', maintenanceItems, {
            //     open: this.appCache.get('fleet-ops:sidebar:maintenance:open', false),
            //     onToggle: (open) => this.appCache.set('fleet-ops:sidebar:maintenance:open', open),
            // }),
            createPanel('menu.connectivity', 'connectivity', connectivityItems, {
                open: this.appCache.get('fleet-ops:sidebar:connectivity:open', false),
                onToggle: (open) => this.appCache.set('fleet-ops:sidebar:connectivity:open', open),
            }),
            createPanel('menu.analytics', 'analytics', analyticsItems, {
                open: this.appCache.get('fleet-ops:sidebar:analytics:open', false),
                onToggle: (open) => this.appCache.set('fleet-ops:sidebar:analytics:open', open),
            }),
            createPanel('menu.settings', 'settings', settingsItems, {
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
