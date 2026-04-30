import Service, { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { underscore } from '@ember/string';

export class RouteEngineRegistry {
    @tracked engines = {};
}

export default class RouteEngineService extends Service {
    @service universe;
    @service currentUser;
    registry = this.#initializeRegistry();

    get availableServices() {
        return Object.entries(this.registry.engines)
            .filter(([, registration]) => registration?.capabilities?.display === true)
            .map(([key, registration]) => ({
                key,
                name: registration?.engine?.name ?? key,
            }));
    }

    register(name, engine, capabilities = {}) {
        const key = underscore(name);
        const normalizedCapabilities = {
            display: capabilities.display === true || typeof engine?.computeRoute === 'function',
        };

        if (!normalizedCapabilities.display) {
            throw new Error(`Cannot register "${name}" as a route engine without computeRoute() support`);
        }

        this.registry.engines = {
            ...this.registry.engines,
            [key]: {
                engine,
                capabilities: normalizedCapabilities,
            },
        };
    }

    unregister(name) {
        const key = underscore(name);
        const rest = { ...this.registry.engines };
        delete rest[key];
        this.registry.engines = rest;
    }

    get(name) {
        const key = underscore(name ?? this.getDisplayEngine());
        const registration = this.registry.engines[key] ?? this.registry.engines.osrm;
        return registration?.engine ?? null;
    }

    getDisplayEngine(fallback = 'osrm') {
        const routingSettings = this.currentUser.getOption('routing', {});
        return routingSettings.routing_display_engine ?? routingSettings.router ?? fallback;
    }

    getOptimizationEngine(fallback = null) {
        const routingSettings = this.currentUser.getOption('routing', {});
        return routingSettings.routing_optimization_engine ?? routingSettings.router ?? this.getDisplayEngine(fallback ?? 'osrm');
    }

    async compute(name, waypoints, options = {}) {
        const engine = this.get(name);
        if (!engine || typeof engine.computeRoute !== 'function') {
            throw new Error(`No route engine registered as "${name}"`);
        }

        return engine.computeRoute(waypoints, options);
    }

    #initializeRegistry() {
        const registry = 'registry:route-engines';
        const application = this.universe.getApplicationInstance();
        if (!application.hasRegistration(registry)) {
            application.register(registry, new RouteEngineRegistry(), { instantiate: false });
        }

        return application.resolveRegistration(registry);
    }
}
