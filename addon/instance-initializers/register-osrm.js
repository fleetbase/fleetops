import { RoutingControl } from '../services/leaflet-routing-control';
import { OSRMv1 } from '@fleetbase/leaflet-routing-machine';

export function initialize(owner) {
    // Register OSRM as route optimization service
    const routeOptimization = owner.lookup('service:route-optimization');
    const routeEngine = owner.lookup('service:route-engine');
    const osrm = owner.lookup('service:osrm');
    if (routeOptimization && osrm) {
        routeOptimization.register('osrm', osrm);
    }

    if (routeEngine && osrm) {
        routeEngine.register('osrm', osrm, { display: true });
    }

    // Register OSRM as Routing Controler
    const leafletRoutingControl = owner.lookup('service:leaflet-routing-control');
    if (leafletRoutingControl && osrm) {
        const router = new OSRMv1({ profile: 'driving' });
        // Resolved per request: the routing settings (and the organization's or the
        // admin's OSRM server) load after this initializer runs.
        Object.defineProperty(router.options, 'serviceUrl', {
            get: () => `${osrm.routingHost()}/route/v1`,
            configurable: true,
        });
        leafletRoutingControl.register(
            'osrm',
            new RoutingControl({
                name: 'OSRM',
                router,
            })
        );
    }
}

export default {
    initialize,
};
