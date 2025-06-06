import Engine from '@ember/engine';
import loadInitializers from 'ember-load-initializers';
import Resolver from 'ember-resolver';
import config from './config/environment';
import services from '@fleetbase/ember-core/exports/services';
import NavigatorAppConfigComponent from './components/admin/navigator-app';
import WidgetFleetOpsKeyMetricsComponent from './components/widget/fleet-ops-key-metrics';
import AdminAvatarManagementComponent from './components/admin/avatar-management';
import CustomerOrdersComponent from './components/customer/orders';
import CustomerAdminSettingsComponent from './components/customer/admin-settings';
import OrderTrackingLookupComponent from './components/order-tracking-lookup';
import { RoutingControl } from './services/leaflet-routing-control';
import { OSRMv1 } from '@fleetbase/leaflet-routing-machine';
import getRoutingHost from '@fleetbase/ember-core/utils/get-routing-host';

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

        // register menu item for tracking order
        universe.registerMenuItem('auth:login', 'Track Order', {
            route: 'virtual',
            slug: 'track-order',
            icon: 'barcode',
            type: 'link',
            wrapperClass: 'btn-block py-1 border dark:border-gray-700 border-gray-200 hover:opacity-50',
            component: OrderTrackingLookupComponent,
            onClick: (menuItem) => {
                universe.transitionMenuItem('virtual', menuItem);
            },
        });

        // Register OSRM as route optimization service
        const routeOptimization = app.lookup('service:route-optimization');
        const osrm = app.lookup('service:osrm');
        if (routeOptimization && osrm) {
            routeOptimization.register('osrm', osrm);
        }

        // Register OSRM as Routing Controler
        const leafletRoutingControl = app.lookup('service:leaflet-routing-control');
        if (leafletRoutingControl) {
            const routingHost = getRoutingHost();
            leafletRoutingControl.register(
                'osrm',
                new RoutingControl({
                    name: 'OSRM',
                    router: new OSRMv1({
                        serviceUrl: `${routingHost}/route/v1`,
                        profile: 'driving',
                    }),
                })
            );
        }

        // widgets for registry
        const KeyMetricsWidgetDefinition = {
            widgetId: 'fleet-ops-key-metrics-widget',
            name: 'Fleet-Ops Metrics',
            description: 'Key metrics from Fleet-Ops.',
            icon: 'truck',
            component: WidgetFleetOpsKeyMetricsComponent,
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
            'fleet-ops:component:live-map-drawer',
            'fleet-ops:component:vehicle-panel',
            'fleet-ops:component:driver-panel',
            'fleet-ops:component:order-config-manager',
            'fleet-ops:component:contact-form-panel',
            'fleet-ops:component:contact-form-panel:details',
            'fleet-ops:component:customer-form-panel',
            'fleet-ops:component:customer-form-panel:details',
            'fleet-ops:component:driver-form-panel',
            'fleet-ops:component:driver-form-panel:details',
            'fleet-ops:component:fleet-form-panel',
            'fleet-ops:component:fleet-form-panel:details',
            'fleet-ops:component:place-form-panel',
            'fleet-ops:component:place-form-panel:details',
            'fleet-ops:component:vehicle-form-panel',
            'fleet-ops:component:vehicle-form-panel:details',
            'fleet-ops:component:vendor-form-panel:edit',
            'fleet-ops:component:vendor-form-panel:edit:details',
            'fleet-ops:component:vendor-form-panel:create',
            'fleet-ops:component:vendor-form-panel:create:details',
            'fleet-ops:component:issue-form-panel',
            'fleet-ops:component:issue-form-panel:details',
            'fleet-ops:component:fuel-report-form-panel',
            'fleet-ops:component:fuel-report-form-panel:details',
            'fleet-ops:contextmenu:vehicle',
            'fleet-ops:contextmenu:driver',
            'fleet-ops:template:operations:orders:view',
            'fleet-ops:template:operations:orders:new',
            'fleet-ops:template:operations:orders:new:entities-input',
            'fleet-ops:template:operations:orders:new:entities-input:entity',
            'fleet-ops:template:settings:routing',
        ]);

        universe.afterBoot(function (universe) {
            universe.registerMenuItems('customer-portal:sidebar', [
                universe._createMenuItem('Orders', 'customer-portal.portal.virtual', { icon: 'boxes-packing', component: CustomerOrdersComponent }),
            ]);
            universe.registerRenderableComponent('@fleetbase/customer-portal-engine', 'customer-portal:admin-settings', CustomerAdminSettingsComponent);
        });
    };
}

loadInitializers(FleetOpsEngine, modulePrefix);
