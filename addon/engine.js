import Engine from '@ember/engine';
import loadInitializers from 'ember-load-initializers';
import Resolver from 'ember-resolver';
import config from './config/environment';
import services from '@fleetbase/ember-core/exports/services';
import AdminVisibilityControlsComponent from './components/admin/visibility-controls';
import NavigatorAppConfigComponent from './components/admin/navigator-app';
import FleetOpsKeyMetricsWidget from './components/widget/fleet-ops-key-metrics';
import AdminAvatarManagementComponent from './components/admin/avatar-management';

const { modulePrefix } = config;
const externalRoutes = ['console', 'extensions'];

export default class FleetOpsEngine extends Engine {
    modulePrefix = modulePrefix;
    Resolver = Resolver;
    dependencies = {
        services,
        externalRoutes,
    };
    setupExtension = function (app, engine, universe) {
        // register menu item in header
        universe.registerHeaderMenuItem('Fleet-Ops', 'console.fleet-ops', { icon: 'route', priority: 0 });

        // register admin settings -- create a fleet-ops menu panel with it's own setting options
        universe.registerAdminMenuPanel(
            'Fleet-Ops Config',
            [
                {
                    title: 'Visibility Controls',
                    icon: 'eye',
                    component: AdminVisibilityControlsComponent,
                },
                {
                    title: 'Navigator App',
                    icon: 'location-arrow',
                    component: NavigatorAppConfigComponent,
                },
                {
                    title: 'Avatar Managemenet',
                    icon: 'images',
                    component: AdminAvatarManagementComponent,
                },
            ],
            {
                slug: 'fleet-ops',
            }
        );

        // widgets for registry
        const KeyMetricsWidgetDefinition = {
            widgetId: 'fleet-ops-key-metrics-widget',
            name: 'Fleet-Ops Metrics',
            description: 'Key metrics from Fleet-Ops.',
            icon: 'truck',
            component: FleetOpsKeyMetricsWidget,
            grid_options: { w: 12, h: 12, minW: 8, minH: 12 },
            options: {
                title: 'Fleet-Ops Metrics',
            },
        };

        // register widgets
        universe.registerDefaultDashboardWidgets([KeyMetricsWidgetDefinition]);
        universe.registerDashboardWidgets([KeyMetricsWidgetDefinition]);

        // create all registries necessary
        universe.createRegistries([
            'engine:fleet-ops',
            'component:vehicle-panel',
            'component:driver-panel',
            'component:order-config-manager',
            'contextmenu:vehicle',
            'contextmenu:driver',
            'fleet-ops:template:operations:orders:view',
            'fleet-ops:template:operations:orders:new',
        ]);
    };
}

loadInitializers(FleetOpsEngine, modulePrefix);
