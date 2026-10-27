import Service, { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { underscore } from '@ember/string';
import isRoutingProviderAllowed from '../utils/routing-providers';

export class RoutingControlRegistry {
    @tracked routers = {};
}

export class RoutingControl {
    @tracked name;
    @tracked router;
    @tracked formatter;

    constructor(init) {
        const { name, router, formatter } = typeof init === 'function' ? init() : init;
        this.name = name;
        this.router = router;
        this.formatter = formatter;
    }
}

export default class LeafletRoutingControlService extends Service {
    @service universe;
    @service currentUser;
    registry = this.#initializeRegistry();

    get availableEngines() {
        return Object.keys(this.registry.routers).map(underscore);
    }

    get availableServices() {
        return Object.entries(this.registry.routers).map(([key, control]) => ({
            key,
            name: control.name ?? key,
        }));
    }

    register(name, getter) {
        this.registry.routers = {
            ...this.registry.routers,
            [underscore(name)]: getter,
        };
    }

    /* eslint-disable no-unused-vars */
    unregister(name) {
        let key = underscore(name);
        let { [key]: _, ...rest } = this.registry.routers;
        this.registry.routers = rest;
    }

    get(name) {
        name = name ?? this.getRouter();
        // A switched-off engine is never used, even when named explicitly
        if (!isRoutingProviderAllowed(name, this.currentUser.getOption('routing', {})?.allowed_providers)) {
            name = this.getRouter();
        }
        let router = this.registry.routers[underscore(name)];
        if (!router) {
            // Fall back to Valhalla (the platform's routing engine), then OSRM
            router = this.registry.routers.valhalla ?? this.registry.routers.osrm;
        }

        return router;
    }

    getRouter(fallback = 'valhalla') {
        const routingSettings = this.currentUser.getOption('routing', { router: fallback ?? 'valhalla' });
        return routingSettings.router;
    }

    #initializeRegistry() {
        const registry = 'registry:routing-controls';
        const application = this.universe.getApplicationInstance();
        if (!application.hasRegistration(registry)) {
            application.register(registry, new RoutingControlRegistry(), { instantiate: false });
        }

        return application.resolveRegistration(registry);
    }
}
