import { MenuItem, Widget, ExtensionComponent } from '@fleetbase/ember-core/contracts';

export default {
    setupExtension(app, universe) {
        const menuService = universe.getService('menu');
        const registryService = universe.getService('registry');
        const widgetService = universe.getService('widget');

        // Register header navigation
        menuService.registerHeaderMenuItem('Fleet-Ops', 'console.fleet-ops', { icon: 'route', priority: 0 });

        // Register admin sections
        menuService.registerAdminMenuPanel(
            'Fleet-Ops Config',
            [
                new MenuItem({
                    title: 'Navigator App',
                    icon: 'location-arrow',
                    component: new ExtensionComponent('@fleetbase/fleetops-engine', 'admin/navigator-app'),
                })
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
            new Widget({
                id: 'fleet-ops-key-metrics-widget',
                name: 'Fleet-Ops Metrics',
                description: 'Key metrics from Fleet-Ops.',
                icon: 'truck',
                component: new ExtensionComponent('@fleetbase/fleetops-engine', 'widget/fleet-ops-key-metrics'),
                grid_options: { w: 12, h: 12, minW: 8, minH: 12 },
                default: true,
            }),
        ];

        widgetService.registerWidgets('dashboard', widgets);
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
            'fleet-ops:contextmenu:vehicle',
            'fleet-ops:contextmenu:driver',
            'fleet-ops:component:order:details',
            'fleet-ops:component:order:form',
            'fleet-ops:component:order:form:payload:entity',
            'fleet-ops:component:order:form:payload:entity:form',
            'fleet-ops:template:settings:routing',
        ]);
    },
};
