import getRoutingHost from '@fleetbase/ember-core/utils/get-routing-host';
import { RoutingControl } from '../services/leaflet-routing-control';
import { OSRMv1 } from '@fleetbase/leaflet-routing-machine';

export function initialize(owner) {
    // Register OSRM as route optimization service
    const routeOptimization = owner.lookup('service:route-optimization');
    const osrm = owner.lookup('service:osrm');
    if (routeOptimization && osrm) {
        routeOptimization.register('osrm', osrm);
    }

    // Register OSRM as Routing Controler
    const leafletRoutingControl = owner.lookup('service:leaflet-routing-control');
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
}

export default {
    initialize,
};
