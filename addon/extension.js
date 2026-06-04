import { MenuItem, Widget, ExtensionComponent } from '@fleetbase/ember-core/contracts';

export default {
    setupExtension(app, universe) {
        const menuService = universe.getService('menu');
        const registryService = universe.getService('registry');
        const widgetService = universe.getService('widget');

        // Register header navigation
        menuService.registerHeaderMenuItem('Fleet-Ops', 'console.fleet-ops', {
            icon: 'route',
            priority: 0,
            description: 'Dispatch, fleet management, driver tracking, and logistics operations.',
            shortcuts: [
                {
                    title: 'Orders',
                    description: 'Create, dispatch, and track delivery orders in real time.',
                    icon: 'boxes-stacked',
                    route: 'console.fleet-ops.operations.orders',
                },
                {
                    title: 'Places',
                    description: 'Manage saved locations, addresses, and points of interest.',
                    icon: 'location-dot',
                    route: 'console.fleet-ops.management.places',
                },
                {
                    title: 'Drivers',
                    description: 'Manage driver profiles, assignments, and live locations.',
                    icon: 'id-card',
                    route: 'console.fleet-ops.management.drivers',
                },
                {
                    title: 'Vehicles',
                    description: 'View and manage your vehicle fleet and telematics.',
                    icon: 'truck',
                    route: 'console.fleet-ops.management.vehicles',
                },
                {
                    title: 'Fleets',
                    description: 'Organise drivers and vehicles into operational fleets.',
                    icon: 'layer-group',
                    route: 'console.fleet-ops.management.fleets',
                },
                {
                    title: 'Service Rates',
                    description: 'Configure pricing rules and service rate cards.',
                    icon: 'tags',
                    route: 'console.fleet-ops.operations.service-rates',
                },
                {
                    title: 'Devices',
                    description: 'Manage connected telematics devices and their sensor data.',
                    icon: 'microchip',
                    route: 'console.fleet-ops.connectivity.devices',
                },
                {
                    title: 'Reports',
                    description: 'Generate and review operational analytics reports.',
                    icon: 'chart-bar',
                    route: 'console.fleet-ops.analytics.reports',
                },
                {
                    title: 'Orchestrator',
                    description: 'Intelligently allocate and dispatch orders to available drivers.',
                    icon: 'diagram-project',
                    route: 'console.fleet-ops.operations.orchestrator',
                },
            ],
        });

        // Register admin sections
        menuService.registerAdminMenuPanel(
            'Fleet-Ops Config',
            [
                new MenuItem({
                    title: 'Routing',
                    icon: 'route',
                    component: new ExtensionComponent('@fleetbase/fleetops-engine', 'admin/routing-settings'),
                }),
                new MenuItem({
                    title: 'Map',
                    icon: 'map',
                    component: new ExtensionComponent('@fleetbase/fleetops-engine', 'admin/map-settings'),
                }),
                new MenuItem({
                    title: 'Navigator App',
                    icon: 'location-arrow',
                    component: new ExtensionComponent('@fleetbase/fleetops-engine', 'admin/navigator-app'),
                }),
            ],
            {
                slug: 'fleet-ops',
            }
        );

        // Register track order button
        menuService.registerMenuItem(
            'auth:login',
            new MenuItem({
                title: 'Track Order',
                route: 'virtual',
                slug: 'track-order',
                icon: 'barcode',
                type: 'link',
                wrapperClass: 'btn-block py-1 border dark:border-gray-700 border-gray-200 hover:opacity-50',
                component: new ExtensionComponent('@fleetbase/fleetops-engine', 'order-tracking-lookup'),
                onClick: (menuItem) => {
                    universe.transitionMenuItem('virtual', menuItem);
                },
            })
        );

        // Register widgets
        this.registerWidgets(widgetService);

        // Create registries
        this.createRegistries(registryService);

        // Register console home guidance
        this.registerHomeComponents(registryService);

        // Setup customer portal
        const isCustomerPortalInstalled = universe.extensionManager.isInstalled('@fleetbase/customer-portal-engine');
        if (isCustomerPortalInstalled) {
            universe.whenEngineLoaded('@fleetbase/customer-portal-engine', () => {
                universe.extensionManager.ensureEngineLoaded('@fleetbase/fleetops-engine');
            });
        }
    },

    registerWidgets(widgetService) {
        const widgets = [
            // Legacy monolithic 13-tile widget — kept registered for one release as
            // users have it pinned to existing dashboards. The new KPI tile widgets
            // below supersede it. Scheduled for removal in the next major.
            new Widget({
                id: 'fleet-ops-key-metrics-widget',
                name: 'Fleet-Ops Metrics (Legacy)',
                description: 'DEPRECATED — replaced by individual KPI tile widgets. Will be removed in the next major release.',
                icon: 'truck',
                component: new ExtensionComponent('@fleetbase/fleetops-engine', 'widget/fleet-ops-key-metrics'),
                grid_options: { w: 12, h: 8, minW: 8, minH: 6 },
                category: 'Legacy',
                default: false,
            }),

            // Small KPI tile widgets — S-tier, each wraps <Widget::KpiTile> with a
            // fixed slug pointing at GET /fleet-ops/metrics/{slug}.
            new Widget({
                id: 'fleet-ops-kpi-earnings-widget',
                name: 'Earnings (30d)',
                description: 'Total earnings over the last 30 days with trend.',
                icon: 'sack-dollar',
                component: new ExtensionComponent('@fleetbase/fleetops-engine', 'widget/kpi-earnings'),
                grid_options: { w: 3, h: 4, minW: 3, minH: 4 },
                category: 'KPI Tiles',
                default: true,
            }),
            new Widget({
                id: 'fleet-ops-kpi-aov-widget',
                name: 'Avg Order Value (30d)',
                description: 'Average revenue per completed order with trend.',
                icon: 'receipt',
                component: new ExtensionComponent('@fleetbase/fleetops-engine', 'widget/kpi-aov'),
                grid_options: { w: 3, h: 4, minW: 3, minH: 4 },
                category: 'KPI Tiles',
                default: true,
            }),
            new Widget({
                id: 'fleet-ops-kpi-distance-widget',
                name: 'Distance Travelled (30d)',
                description: 'Total kilometres delivered over the last 30 days.',
                icon: 'route',
                component: new ExtensionComponent('@fleetbase/fleetops-engine', 'widget/kpi-distance'),
                grid_options: { w: 3, h: 4, minW: 3, minH: 4 },
                category: 'KPI Tiles',
                default: false,
            }),
            new Widget({
                id: 'fleet-ops-kpi-active-orders-widget',
                name: 'Active Orders',
                description: 'Live count of orders currently in flight.',
                icon: 'bolt',
                component: new ExtensionComponent('@fleetbase/fleetops-engine', 'widget/kpi-active-orders'),
                grid_options: { w: 3, h: 4, minW: 3, minH: 4 },
                category: 'KPI Tiles',
                default: true,
            }),
            new Widget({
                id: 'fleet-ops-kpi-drivers-online-widget',
                name: 'Drivers Online',
                description: 'Live count of drivers currently active on a job.',
                icon: 'id-card',
                component: new ExtensionComponent('@fleetbase/fleetops-engine', 'widget/kpi-drivers-online'),
                grid_options: { w: 3, h: 4, minW: 3, minH: 4 },
                category: 'KPI Tiles',
                default: true,
            }),
            new Widget({
                id: 'fleet-ops-kpi-open-issues-widget',
                name: 'Open Issues',
                description: 'Pending issues across the fleet (lower is better).',
                icon: 'triangle-exclamation',
                component: new ExtensionComponent('@fleetbase/fleetops-engine', 'widget/kpi-open-issues'),
                grid_options: { w: 3, h: 4, minW: 3, minH: 4 },
                category: 'KPI Tiles',
                default: false,
            }),

            // Analytics widgets — composed visualizations powered by /fleet-ops/analytics/*.
            new Widget({
                id: 'fleet-ops-operations-pulse-widget',
                name: 'Operations Pulse',
                description: 'Live operational snapshot with day-over-day deltas.',
                icon: 'wave-pulse',
                component: new ExtensionComponent('@fleetbase/fleetops-engine', 'widget/operations-pulse'),
                grid_options: { w: 6, h: 6, minW: 5, minH: 5 },
                category: 'Analytics',
                default: false,
            }),
            new Widget({
                id: 'fleet-ops-live-fleet-widget',
                name: 'Live Fleet Map',
                description: 'Real-time driver positions and active routes.',
                icon: 'map-location-dot',
                component: new ExtensionComponent('@fleetbase/fleetops-engine', 'widget/live-fleet'),
                grid_options: { w: 8, h: 11, minW: 8, minH: 8 },
                category: 'Maps',
                default: true,
            }),
            new Widget({
                id: 'fleet-ops-revenue-trend-widget',
                name: 'Revenue Trend',
                description: 'Revenue over time with period comparison.',
                icon: 'chart-line',
                component: new ExtensionComponent('@fleetbase/fleetops-engine', 'widget/revenue-trend'),
                grid_options: { w: 4, h: 11, minW: 4, minH: 8 },
                category: 'Analytics',
                default: true,
            }),
            new Widget({
                id: 'fleet-ops-orders-by-status-widget',
                name: 'Order Volume by Status',
                description: 'Daily stacked bars of order counts by status.',
                icon: 'chart-column',
                component: new ExtensionComponent('@fleetbase/fleetops-engine', 'widget/orders-by-status'),
                grid_options: { w: 6, h: 6, minW: 5, minH: 5 },
                category: 'Analytics',
                default: false,
            }),
            new Widget({
                id: 'fleet-ops-on-time-delivery-widget',
                name: 'On-Time Delivery',
                description: 'Percentage of deliveries completed within the SLA window.',
                icon: 'clock',
                component: new ExtensionComponent('@fleetbase/fleetops-engine', 'widget/on-time-delivery'),
                grid_options: { w: 3, h: 4, minW: 3, minH: 4 },
                category: 'Analytics',
                default: false,
            }),
            new Widget({
                id: 'fleet-ops-top-drivers-widget',
                name: 'Top Drivers',
                description: 'Driver leaderboard sortable by orders, on-time %, or distance.',
                icon: 'medal',
                component: new ExtensionComponent('@fleetbase/fleetops-engine', 'widget/top-drivers'),
                grid_options: { w: 6, h: 6, minW: 5, minH: 5 },
                category: 'Analytics',
                default: true,
            }),
            new Widget({
                id: 'fleet-ops-fuel-efficiency-widget',
                name: 'Fuel Cost & Efficiency',
                description: 'Weekly fuel cost and cost-per-km trend.',
                icon: 'gas-pump',
                component: new ExtensionComponent('@fleetbase/fleetops-engine', 'widget/fuel-efficiency'),
                grid_options: { w: 6, h: 6, minW: 5, minH: 5 },
                category: 'Analytics',
                default: false,
            }),
            new Widget({
                id: 'fleet-ops-issues-insights-widget',
                name: 'Issues Insights',
                description: 'Open/resolved issues, category breakdown, and average resolution time.',
                icon: 'triangle-exclamation',
                component: new ExtensionComponent('@fleetbase/fleetops-engine', 'widget/issues-insights'),
                grid_options: { w: 6, h: 6, minW: 5, minH: 5 },
                category: 'Analytics',
                default: false,
            }),
            new Widget({
                id: 'fleet-ops-maintenance-overview-widget',
                name: 'Maintenance Overview',
                description: 'Overdue, scheduled, and YTD maintenance spend.',
                icon: 'wrench',
                component: new ExtensionComponent('@fleetbase/fleetops-engine', 'widget/maintenance-overview'),
                grid_options: { w: 6, h: 6, minW: 5, minH: 5 },
                category: 'Analytics',
                default: true,
            }),
            new Widget({
                id: 'fleet-ops-geofence-violations-widget',
                name: 'Geofence Violations',
                description: 'Dwell-time outliers and geofence event hotspots.',
                icon: 'location-crosshairs',
                component: new ExtensionComponent('@fleetbase/fleetops-engine', 'widget/geofence-violations'),
                grid_options: { w: 6, h: 6, minW: 5, minH: 5 },
                category: 'Analytics',
                default: false,
            }),
        ];

        widgetService.registerWidgets('dashboard', widgets);
    },

    registerHomeComponents(registryService) {
        registryService.registerRenderableComponent('console:home:before-dashboard', new ExtensionComponent('@fleetbase/fleetops-engine', 'home/getting-started-guidance'));
    },

    createRegistries(registryService) {
        registryService.createRegistries([
            'engine:fleet-ops',
            'fleet-ops:component:map:drawer',
            'fleet-ops:component:vehicle:details',
            'fleet-ops:component:driver:details',
            'fleet-ops:component:order-config-manager',
            'fleet-ops:component:contact:form',
            'fleet-ops:component:contact:form:details',
            'fleet-ops:component:customer:form',
            'fleet-ops:component:customer:form:details',
            'fleet-ops:component:driver:form',
            'fleet-ops:component:driver:form:details',
            'fleet-ops:component:fleet:form',
            'fleet-ops:component:fleet:form:details',
            'fleet-ops:component:place:form',
            'fleet-ops:component:place:form:details',
            'fleet-ops:component:vehicle:form',
            'fleet-ops:component:vehicle:form:details',
            'fleet-ops:component:vendor:form:edit',
            'fleet-ops:component:vendor:form:edit:details',
            'fleet-ops:component:vendor:form:create',
            'fleet-ops:component:vendor:form:create:details',
            'fleet-ops:component:issue:form',
            'fleet-ops:component:issue:form:details',
            'fleet-ops:component:fuel-report:form',
            'fleet-ops:component:fuel-report:form:details',
            'fleet-ops:component:maintenance:form',
            'fleet-ops:component:maintenance:form:details',
            'fleet-ops:component:maintenance:details',
            'fleet-ops:component:work-order:form',
            'fleet-ops:component:work-order:form:details',
            'fleet-ops:component:work-order:details',
            'fleet-ops:component:equipment:form',
            'fleet-ops:component:equipment:form:details',
            'fleet-ops:component:equipment:details',
            'fleet-ops:component:part:form',
            'fleet-ops:component:part:form:details',
            'fleet-ops:component:part:details',
            'fleet-ops:contextmenu:vehicle',
            'fleet-ops:contextmenu:driver',
            'fleet-ops:component:order:details',
            'fleet-ops:component:order:form',
            'fleet-ops:component:order:form:payload:entity',
            'fleet-ops:component:order:form:payload:entity:form',
            'fleet-ops:template:settings:routing',
            'fleet-ops:template:settings:orchestrator',
            'fleet-ops:component:admin:routing-settings',
        ]);
    },
};
