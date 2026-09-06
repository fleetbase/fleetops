import StubEventedService from '../utils/stub-evented-service';

/**
 * Stand-in for the host console's `hostRouter` service (a RouterService proxy the console injects
 * into engines; see `@fleetbase/ember-core/exports/services`). The addon calls `transitionTo`
 * (272 sites), `refresh` (58), `currentRouteName` (12) and `on`/`off` for `routeDidChange`.
 * Router-shaped surface: transitions resolve immediately and are recorded on `calls` so tests can
 * assert on them.
 */
export default class HostRouterService extends StubEventedService {
    calls = [];

    currentRouteName = 'console.fleet-ops.operations.orders.index';
    currentURL = '/fleet-ops';
    currentRoute = { name: 'console.fleet-ops.operations.orders.index', params: {}, queryParams: {} };
    rootURL = '/';

    transitionTo(...args) {
        this.calls.push({ method: 'transitionTo', args });
        return Promise.resolve();
    }

    replaceWith(...args) {
        this.calls.push({ method: 'replaceWith', args });
        return Promise.resolve();
    }

    urlFor(routeName) {
        this.calls.push({ method: 'urlFor', args: [routeName] });
        return `/${String(routeName).replace(/\./g, '/')}`;
    }

    isActive(routeName) {
        this.calls.push({ method: 'isActive', args: [routeName] });
        return routeName === this.currentRouteName;
    }

    refresh() {
        this.calls.push({ method: 'refresh', args: [] });
        return Promise.resolve();
    }
}
