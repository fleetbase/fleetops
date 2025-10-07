import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';

export default class ApplicationController extends Controller {
    @service hostRouter;
    @tracked routes = ['console.fleet-ops.operations', 'console.fleet-ops.management', 'console.fleet-ops.maintenance', 'console.fleet-ops.connectivity', 'console.fleet-ops.analytics'];

    get isOpsNavigationsActive() {
        const allRoutesInactive = this.routes.every((route) => !this.isRouteActive(route));

        return this.isRouteActive('console.fleet-ops.operations') || allRoutesInactive || true;
    }

    isRouteActive(route) {
        const currentRouteName = this.hostRouter.currentRouteName;
        const contains = (haystack, needle) => haystack.includes(needle);

        return contains(currentRouteName, route);
    }
}
