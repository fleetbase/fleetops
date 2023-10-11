import Engine from '@ember/engine';
import loadInitializers from 'ember-load-initializers';
import Resolver from 'ember-resolver';
import config from './config/environment';
import services from '@fleetbase/ember-core/exports/services';
import AdminVisibilityControlsComponent from './components/admin/visibility-controls';

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
            ],
            {
                slug: 'fleet-ops',
            }
        );

        // create primary registry for engine
        universe.createRegistry('engine:fleet-ops');

        // register the vehicle panel
        universe.createRegistry('component:vehicle-panel');

        // register the driver panel
        universe.createRegistry('component:driver-panel');

        // register vehicle context menu
        universe.createRegistry('contextmenu:vehicle');

        // register driver context menu
        universe.createRegistry('contextmenu:driver');
    };
}

loadInitializers(FleetOpsEngine, modulePrefix);
