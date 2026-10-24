import Service, { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { underscore } from '@ember/string';
import isRoutingProviderAllowed from '../utils/routing-providers';

export class RouteOptimizationRegistry {
    @tracked engines = {};
}

export default class RouteOptimizationService extends Service {
    @service universe;
    @service currentUser;
    registry = this.#initializeRegistry();

    get availableEngines() {
        return this.availableServices.map(({ key }) => underscore(key));
    }

    /**
     * Optimization engines the organization may choose: registered ones the administrator has not switched off.
     */
    get availableServices() {
        return this.allServices.filter(({ key }) => this.isAllowed(key));
    }

    /**
     * Every registered optimization engine, for admin screens that set platform defaults.
     */
    get allServices() {
        return Object.entries(this.registry.engines).map(([key, engine]) => ({
            key,
            name: engine.name ?? key,
        }));
    }

    isAllowed(name) {
        return isRoutingProviderAllowed(name, this.currentUser.getOption('routing', {})?.allowed_providers);
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
        if (!this.isAllowed(name)) {
            return Promise.reject(new Error(`Route optimization engine "${name}" has been switched off by the administrator`));
        }

        let engine = this.registry.engines[underscore(name)];
        if (!engine) {
            return Promise.reject(new Error(`No route optimization engine registered as "${name}"`));
        }
        return engine.optimize(params, options);
    }

    handler(name, context, data) {
        if (!this.isAllowed(name)) {
            throw new Error(`Route optimization engine "${name}" has been switched off by the administrator`);
        }

        let engine = this.registry.engines[underscore(name)];
        if (!engine) {
            throw new Error(`No route optimization engine registered as "${name}"`);
        }
        return engine.handler(context, data);
    }

    #initializeRegistry() {
        const registry = 'registry:route-optimization-engines';
        const application = this.universe.getApplicationInstance();
        if (!application.hasRegistration(registry)) {
            application.register(registry, new RouteOptimizationRegistry(), { instantiate: false });
        }

        return application.resolveRegistration(registry);
    }
}
