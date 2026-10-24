import Service, { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { underscore } from '@ember/string';
import isRoutingProviderAllowed from '../utils/routing-providers';

export class RouteEngineRegistry {
    @tracked engines = {};
}

export default class RouteEngineService extends Service {
    @service universe;
    @service currentUser;
    registry = this.#initializeRegistry();

    /**
     * Display engines the organization may choose: registered ones the administrator has not switched off.
     */
    get availableServices() {
        return this.allServices.filter(({ key }) => this.isAllowed(key));
    }

    /**
     * Every registered display engine, for admin screens that set platform defaults.
     */
    get allServices() {
        return Object.entries(this.registry.engines)
            .filter(([, registration]) => registration?.capabilities?.display === true)
            .map(([key, registration]) => ({
                key,
                name: registration?.engine?.name ?? key,
            }));
    }

    isAllowed(name) {
        return isRoutingProviderAllowed(name, this.currentUser.getOption('routing', {})?.allowed_providers);
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
        // A switched-off engine is never used for map routing, even when named explicitly
        const requested = name ?? this.getDisplayEngine();
        const key = underscore(this.isAllowed(requested) ? requested : this.getDisplayEngine());
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
