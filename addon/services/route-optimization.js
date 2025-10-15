import Service, { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { underscore } from '@ember/string';

export class RouteOptimizationRegistry {
    @tracked engines = {};
}

export default class RouteOptimizationService extends Service {
    @service universe;
    registry = this.#initializeRegistry();

    get availableEngines() {
        return Object.keys(this.registry.engines).map(underscore);
    }

    get availableServices() {
        return Object.entries(this.registry.engines).map(([key, engine]) => ({
            key,
            name: engine.name ?? key,
        }));
    }

    register(name, engine) {
        if (typeof engine.optimize !== 'function') {
            throw new Error(`Cannot register "${name}": missing optimize()`);
        }

        this.registry.engines = {
            ...this.registry.engines,
            [underscore(name)]: engine,
        };
    }

    /* eslint-disable no-unused-vars */
    unregister(name) {
        let key = underscore(name);
        let { [key]: _, ...rest } = this.registry.engines;
        this.registry.engines = rest;
    }

    optimize(name, params = {}, options = {}) {
        let engine = this.registry.engines[underscore(name)];
        if (!engine) {
            return Promise.reject(new Error(`No route optimization engine registered as "${name}"`));
        }
        return engine.optimize(params, options);
    }

    handler(name, context, data) {
        let engine = this.registry.engines[underscore(name)];
        if (!engine) {
            throw new Error(`No route optimization engine registered as "${name}"`);
        }
        return engine.handler(context, data);
    }

    #initializeRegistry() {
        const registry = 'registry:route-optimization-engines';
        const application = typeof this.universe?.getApplicationInstance === 'function' ? this.universe.getApplicationInstance() : window.Fleetbase;
        if (!application.hasRegistration(registry)) {
            application.register(registry, new RouteOptimizationRegistry(), { instantiate: false });
        }

        return application.resolveRegistration(registry);
    }
}
