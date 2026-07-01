import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import isMenuItemActive from '@fleetbase/ember-ui/utils/is-menu-item-active';

const SECTION_REGISTRY_KEYS = {
    operations: 'universeOperationsMenuItems',
    management: 'universeManagementMenuItems',
    maintenance: 'universeMaintenanceMenuItems',
    connectivity: 'universeConnectivityMenuItems',
    analytics: 'universeAnalyticsMenuItems',
    settings: 'universeSettingsMenuItems',
};

export default class LayoutFleetOpsSidebarComponent extends Component {
    @service universe;
    @service('universe/menu-service') menuService;
    @service intl;
    @service abilities;
    @service fetch;

    @tracked routePrefix = 'console.fleet-ops.';
    @tracked universeMenuItems = [];
    @tracked universeOperationsMenuItems = [];
    @tracked universeManagementMenuItems = [];
    @tracked universeConnectivityMenuItems = [];
    @tracked universeMaintenanceMenuItems = [];
    @tracked universeAnalyticsMenuItems = [];
    @tracked universeSettingsMenuItems = [];
    @tracked universeMenuPanels = [];

    constructor() {
        super(...arguments);
        this.createMenuItemsFromUniverseRegistry();
    }

    get createOrderAction() {
        return {
            label: this.intl.t('common.create-new-resource', { resource: this.intl.t('resource.order')?.toLowerCase() }),
            icon: 'paper-plane',
            iconPrefix: 'fas',
            buttonClass: 'fleet-ops-sidebar-primary-action',
            permission: 'fleet-ops create order',
            onClick: this.onClickCreateOrder,
        };
    }

    get navigationItems() {
        const coreBranches = [
            this.createBranch({
                id: 'operations',
                label: this.intl.t('menu.operations'),
                icon: 'circle-nodes',
                route: 'operations',
                defaultRoute: 'operations.orders',
                keywords: ['dispatch', 'orders', 'live map', 'track vehicles', 'drivers online', 'vehicles online'],
                children: this.operationsItems,
            }),
            this.createBranch({
                id: 'resources',
                label: this.intl.t('menu.resources'),
                icon: 'truck',
                route: 'management',
                defaultRoute: 'management.index',
                requiresVisibleChildren: true,
                keywords: ['drivers', 'vehicles', 'fleets', 'contacts', 'places'],
                children: this.resourcesItems,
            }),
            this.createBranch({
                id: 'maintenance',
                label: this.intl.t('menu.maintenance'),
                icon: 'cog',
                route: 'maintenance',
                defaultRoute: 'maintenance.index',
                requiresVisibleChildren: true,
                keywords: ['work orders', 'equipment', 'parts'],
                children: this.maintenanceItems,
            }),
            this.createBranch({
                id: 'connectivity',
                label: this.intl.t('menu.connectivity'),
                icon: 'satellite-dish',
                route: 'connectivity',
                defaultRoute: 'connectivity.telematics',
                requiresVisibleChildren: true,
                keywords: ['telematics', 'fuel integrations', 'devices', 'sensors'],
                children: this.connectivityItems,
            }),
            this.createBranch({
                id: 'analytics',
                label: this.intl.t('menu.analytics'),
                icon: 'chart-line',
                route: 'analytics',
                defaultRoute: 'analytics.index',
                requiresVisibleChildren: true,
                keywords: ['reports', 'metrics'],
                children: this.analyticsItems,
            }),
            this.createBranch({
                id: 'settings',
                label: this.intl.t('menu.settings'),
                icon: 'gear',
                route: 'settings',
                defaultRoute: 'settings.index',
                requiresVisibleChildren: true,
                keywords: ['configuration', 'navigator', 'map', 'routing', 'notifications'],
                children: this.settingsItems,
            }),
        ];

        return [...coreBranches, ...this.registryRootItems, ...this.registryPanelItems].filter((item) => item.children?.length || item.route || item.url || item.onClick);
    }

    get operationsItems() {
        return this.withRegistryItems('operations', [
            this.createItem('menu.orders', 'map-location-dot', 'operations.orders', 'fleet-ops list order', 'fleet-ops see order', [
                'dashboard',
                'orders',
                'dispatch',
                'tracking',
                'create order',
                'live map',
            ]),
            this.createItem('menu.orchestrator', 'circle-nodes', 'operations.orchestrator', 'fleet-ops list order', 'fleet-ops see order', ['optimize', 'allocate', 'dispatch']),
            this.createItem('menu.scheduler', 'calendar-day', 'operations.scheduler', 'fleet-ops list order', 'fleet-ops see order', ['schedule', 'calendar']),
            this.createItem('menu.order-config', 'diagram-project', 'operations.order-config', 'fleet-ops list order-config', 'fleet-ops see order-config', [
                'configuration',
                'fields',
                'entities',
            ]),
            this.createItem('menu.service-rates', 'file-invoice-dollar', 'operations.service-rates', 'fleet-ops list service-rate', 'fleet-ops see service-rate', ['rates', 'pricing']),
        ]);
    }

    get resourcesItems() {
        return this.withRegistryItems('management', [
            this.createHubItem('Resources Hub', 'layer-group', 'management.index', 'fleet-ops list driver', 'fleet-ops see driver', [
                'resources hub',
                'resource dashboard',
                'resource readiness',
            ]),
            this.createItem('menu.drivers', 'id-card', 'management.drivers', 'fleet-ops list driver', 'fleet-ops see driver', ['driver', 'online drivers']),
            this.createItem('menu.vehicles', 'truck', 'management.vehicles', 'fleet-ops list vehicle', 'fleet-ops see vehicle', ['vehicle', 'track vehicles', 'online vehicles']),
            this.createItem('menu.fleets', 'user-group', 'management.fleets', 'fleet-ops list fleet', 'fleet-ops see fleet', ['fleet', 'teams']),
            this.createItem('menu.vendors', 'warehouse', 'management.vendors', 'fleet-ops list vendor', 'fleet-ops see vendor'),
            this.createItem('menu.contacts', 'address-book', 'management.contacts', 'fleet-ops list contact', 'fleet-ops see contact'),
            this.createItem('menu.places', 'location-dot', 'management.places', 'fleet-ops list place', 'fleet-ops see place'),
            this.createItem('menu.fuel-reports', 'gas-pump', 'management.fuel-reports', 'fleet-ops list fuel-report', 'fleet-ops see fuel-report'),
            this.createItem('menu.fuel-transactions', 'credit-card', 'management.fuel-transactions', 'fleet-ops list fuel-report', 'fleet-ops see fuel-report'),
            this.createItem('menu.issues', 'triangle-exclamation', 'management.issues', 'fleet-ops list issue', 'fleet-ops see issue'),
        ]);
    }

    get maintenanceItems() {
        return this.withRegistryItems('maintenance', [
            this.createHubItem('Maintenance Hub', 'wrench', 'maintenance.index', 'fleet-ops list maintenance-schedule', 'fleet-ops see maintenance-schedule', [
                'maintenance hub',
                'service readiness',
                'maintenance control panel',
            ]),
            this.createItem('Inspection Forms', 'clipboard-check', 'maintenance.inspection-forms', 'fleet-ops list inspection-form', 'fleet-ops see inspection-form'),
            this.createItem('Inspections', 'list-check', 'maintenance.inspection-submissions', 'fleet-ops list inspection-submission', 'fleet-ops see inspection-submission'),
            this.createItem('menu.schedules', 'calendar-alt', 'maintenance.schedules', 'fleet-ops list maintenance-schedule', 'fleet-ops see maintenance-schedule'),
            this.createItem('menu.work-orders', 'clipboard-list', 'maintenance.work-orders', 'fleet-ops list work-order', 'fleet-ops see work-order'),
            this.createItem('menu.maintenances', 'history', 'maintenance.maintenances', 'fleet-ops list maintenance', 'fleet-ops see maintenance'),
            this.createItem('menu.equipment', 'trailer', 'maintenance.equipment', 'fleet-ops list equipment', 'fleet-ops see equipment'),
            this.createItem('menu.parts', 'cog', 'maintenance.parts', 'fleet-ops list part', 'fleet-ops see part'),
        ]);
    }

    get connectivityItems() {
        return this.withRegistryItems('connectivity', [
            this.createHubItem(this.intl.t('menu.telematics'), 'satellite-dish', 'connectivity.telematics', 'fleet-ops list telematic', 'fleet-ops see telematic', ['connectivity hub']),
            this.createItem('menu.fuel-providers', 'gas-pump', 'connectivity.fuel-providers', 'fleet-ops list fuel-report', 'fleet-ops see fuel-report', ['fuel integrations']),
            this.createItem('menu.devices', 'hard-drive', 'connectivity.devices', 'fleet-ops list device', 'fleet-ops see device'),
            this.createItem('menu.sensors', 'temperature-full', 'connectivity.sensors', 'fleet-ops list sensor', 'fleet-ops see sensor'),
            this.createItem('menu.events', 'stream', 'connectivity.events', 'fleet-ops list device-event', 'fleet-ops see device-event'),
        ]);
    }

    get analyticsItems() {
        return this.withRegistryItems('analytics', [
            this.createHubItem('Dashboard', 'chart-line', 'analytics.index', 'iam list report', 'fleet-ops see report', ['dashboard', 'fleetops dashboard', 'metrics']),
            this.createItem('menu.reports', 'file-import', 'analytics.reports', 'iam list report', 'fleet-ops see report'),
        ]);
    }

    get settingsItems() {
        return this.withRegistryItems('settings', [
            this.createHubItem('Settings Hub', 'sliders', 'settings.index', 'fleet-ops view navigator-settings', 'fleet-ops see navigator-settings', [
                'settings hub',
                'configuration dashboard',
                'setup focus',
            ]),
            this.createItem('menu.navigator-app', 'location-arrow', 'settings.navigator-app', 'fleet-ops view navigator-settings', 'fleet-ops see navigator-settings'),
            this.createItem('menu.map', 'map', 'settings.map', 'fleet-ops view map-settings', 'fleet-ops see map-settings'),
            this.createItem('menu.payments', 'cash-register', 'settings.payments', 'fleet-ops view payments', 'fleet-ops see payments'),
            this.createItem('menu.notifications', 'bell', 'settings.notifications', 'fleet-ops view notification-settings', 'fleet-ops see notification-settings'),
            this.createItem('menu.routing', 'route', 'settings.routing', 'fleet-ops view routing-settings', 'fleet-ops see routing-settings'),
            this.createItem('menu.orchestrator', 'circle-nodes', 'settings.orchestrator', 'fleet-ops view routing-settings', 'fleet-ops see routing-settings'),
            this.createItem('menu.scheduling', 'calendar-days', 'settings.scheduling', 'fleet-ops view scheduling-settings', 'fleet-ops see scheduling-settings'),
            this.createItem('menu.custom-fields', 'pen-to-square', 'settings.custom-fields', 'fleet-ops view custom-field', 'fleet-ops see custom-field'),
            this.createItem('menu.avatars', 'icons', 'settings.avatars', 'fleet-ops view avatar', 'fleet-ops see avatar'),
        ]);
    }

    get registryRootItems() {
        return this.sortByPriority(this.universeMenuItems.filter((item) => !item.renderComponentInPlace).map((item) => this.registryItem(item)));
    }

    get registryPanelItems() {
        return this.sortByPriority(
            this.universeMenuPanels.map((panel) => {
                const children = this.sortByPriority((panel.items ?? []).filter((item) => !item.renderComponentInPlace).map((item) => this.registryItem(item)));

                return {
                    id: panel.id ?? panel.slug ?? panel.title,
                    label: panel.intl ? this.intl.t(panel.intl) : panel.title,
                    icon: panel.icon,
                    visible: panel.visible,
                    permission: panel.permission,
                    priority: panel.priority,
                    keywords: [panel.slug, panel.title, panel.intl].filter(Boolean),
                    children,
                };
            })
        );
    }

    get footerRegistryComponents() {
        const sectionItems = Object.entries(SECTION_REGISTRY_KEYS).reduce((components, [section, propertyName]) => {
            components[section] = this[propertyName].filter((item) => item.renderComponentInPlace);
            return components;
        }, {});

        return {
            root: this.universeMenuItems.filter((item) => item.renderComponentInPlace),
            resources: sectionItems.management,
            ...sectionItems,
        };
    }

    createMenuItemsFromUniverseRegistry() {
        const registeredMenuItems = this.menuService.getMenuItems('engine:fleet-ops');

        this.universeMenuPanels = this.menuService.getMenuPanels('engine:fleet-ops');
        this.universeMenuItems = registeredMenuItems.filter((menuItem) => menuItem.section === undefined);
        this.universeOperationsMenuItems = registeredMenuItems.filter((menuItem) => menuItem.section === 'operations');
        this.universeManagementMenuItems = registeredMenuItems.filter((menuItem) => menuItem.section === 'management');
        this.universeMaintenanceMenuItems = registeredMenuItems.filter((menuItem) => menuItem.section === 'maintenance');
        this.universeConnectivityMenuItems = registeredMenuItems.filter((menuItem) => menuItem.section === 'connectivity');
        this.universeAnalyticsMenuItems = registeredMenuItems.filter((menuItem) => menuItem.section === 'analytics');
        this.universeSettingsMenuItems = registeredMenuItems.filter((menuItem) => menuItem.section === 'settings');
    }

    createBranch({ id, label, icon, route, defaultRoute, requiresVisibleChildren = false, children, keywords = [] }) {
        return {
            id,
            label,
            icon,
            route: this.fullRoute(route),
            defaultRoute: this.fullRoute(defaultRoute),
            requiresVisibleChildren,
            children: children.filter((item) => item.visible !== false),
            keywords,
        };
    }

    createItem(intl, icon, route, permission, ability, keywords = []) {
        return {
            priority: this.defaultPriorityForRoute(route),
            label: this.intl.t(intl),
            description: this.intl.t(intl),
            icon,
            route: this.fullRoute(route),
            permission,
            visiblePermission: ability,
            keywords: [intl, route, ...keywords].filter(Boolean),
        };
    }

    createHubItem(label, icon, route, _permission, _ability, keywords = []) {
        return {
            pinnedFirst: true,
            priority: this.defaultPriorityForRoute(route),
            label,
            description: label,
            icon,
            route: this.fullRoute(route),
            isNavigationHub: true,
            keywords: [label, route, ...keywords].filter(Boolean),
        };
    }

    registryItem(menuItem) {
        const registryMenuItem = {
            ...menuItem,
            _virtual: true,
            label: menuItem.intl ? this.intl.t(menuItem.intl) : (menuItem.title ?? menuItem.label),
            description: menuItem.description,
            icon: menuItem.icon,
            iconPrefix: menuItem.iconPrefix,
            permission: menuItem.permission,
            visible: menuItem.visible,
            keywords: [menuItem.slug, menuItem.view, menuItem.section, menuItem.title, menuItem.label, menuItem.intl, ...(menuItem.keywords ?? [])].filter(Boolean),
            activeWhen: () => isMenuItemActive(menuItem.section, menuItem.slug, menuItem.view),
        };

        registryMenuItem.onClick = () => this.universe.transitionMenuItem(`${this.routePrefix}virtual`, registryMenuItem);

        return registryMenuItem;
    }

    withRegistryItems(section, items) {
        const registryProperty = SECTION_REGISTRY_KEYS[section];
        const registryItems = (this[registryProperty] ?? []).filter((item) => !item.renderComponentInPlace).map((item) => this.registryItem(item));

        return this.sortByPriority([...items, ...registryItems]);
    }

    sortByPriority(items = []) {
        return [...items]
            .map((item, index) => ({ item, index }))
            .sort((a, b) => {
                if (a.item.pinnedFirst !== b.item.pinnedFirst) {
                    return a.item.pinnedFirst ? -1 : 1;
                }

                const priorityOrder = (a.item.priority ?? 0) - (b.item.priority ?? 0);

                if (priorityOrder !== 0) {
                    return priorityOrder;
                }

                return a.index - b.index;
            })
            .map(({ item }) => item);
    }

    defaultPriorityForRoute(route) {
        const priorities = {
            'operations.orders': 0,
            'operations.orchestrator': 1,
            'operations.scheduler': 2,
            'operations.order-config': 3,
            'operations.service-rates': 4,
            'management.index': 0,
            'management.drivers': 1,
            'management.vehicles': 2,
            'management.fleets': 3,
            'management.vendors': 4,
            'management.contacts': 5,
            'management.places': 6,
            'management.fuel-reports': 7,
            'management.fuel-transactions': 8,
            'management.issues': 9,
            'maintenance.index': 0,
            'maintenance.schedules': 1,
            'maintenance.work-orders': 2,
            'maintenance.maintenances': 3,
            'maintenance.equipment': 4,
            'maintenance.parts': 5,
            'connectivity.telematics': 0,
            'connectivity.fuel-providers': 1,
            'connectivity.devices': 2,
            'connectivity.sensors': 3,
            'connectivity.events': 4,
            'analytics.index': 0,
            'analytics.reports': 1,
            'settings.index': 0,
            'settings.navigator-app': 1,
            'settings.map': 2,
            'settings.payments': 3,
            'settings.notifications': 4,
            'settings.routing': 5,
            'settings.orchestrator': 6,
            'settings.scheduling': 7,
            'settings.custom-fields': 8,
            'settings.avatars': 9,
        };

        return priorities[route] ?? 0;
    }

    fullRoute(route) {
        if (!route || route.startsWith('console.')) {
            return route;
        }

        return `${this.routePrefix}${route}`;
    }

    @action onClickCreateOrder() {
        if (typeof this.args.onClickCreateOrder === 'function') {
            this.args.onClickCreateOrder();
        }
    }

    @action shouldSyncInitialActiveParent({ activePath = [], currentURL }) {
        const [parent, child] = activePath;
        const normalizedURL = (currentURL ?? '').split('?')[0].replace(/\/+$/, '') || '/';
        const isFleetOpsRootURL = normalizedURL === '/fleet-ops';
        const isDefaultOrdersLanding = parent?.id === 'operations' && child?.route === this.fullRoute('operations.orders') && isFleetOpsRootURL;

        return !isDefaultOrdersLanding;
    }

    @action
    async searchNavigation({ query, limit = 12 }) {
        const trimmedQuery = query?.trim();

        if (!trimmedQuery) {
            return [];
        }

        try {
            const response = await this.fetch.get('search', { query: trimmedQuery, limit }, { namespace: 'int/v1' });

            return response.results ?? [];
        } catch (_) {
            return [];
        }
    }
}
