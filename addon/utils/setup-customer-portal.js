import CustomerOrdersComponent from '../components/customer/orders';
import CustomerAdminSettingsComponent from '../components/customer/admin-settings';
import { MenuItem } from '@fleetbase/ember-core/contracts';
import { setOwner } from '@ember/application';
import { debug } from '@ember/debug';

export default function setupCustomerPortal(fleetopsEngine, universe) {
    const extensionManager = universe.getService('universe/extension-manager');
    const isCustomerPortalInstalled = extensionManager.isInstalled('@fleetbase/customer-portal-engine');
    if (!isCustomerPortalInstalled) return;

    // If customer portal already loaded just run setup
    if (extensionManager.isEngineLoaded('@fleetbase/customer-portal-engine')) {
        const customerPortalEngine = extensionManager.getEngineInstance('@fleetbase/customer-portal-engine');
        if (customerPortalEngine) {
            return setup(customerPortalEngine, fleetopsEngine, universe);
        }
    }

    // Otherwise, wait for customer portal engine to load to run setup
    universe.onEngineLoaded('@fleetbase/customer-portal-engine', (customerPortalEngine) => {
        setup(customerPortalEngine, fleetopsEngine, universe);
    });
}

function setup(customerPortalEngine, fleetopsEngine, universe) {
    // If setup already completed don't run again
    if (customerPortalEngine?._fleetopsSetupCompleted === true) return;

    // Alias FleetOps services into Customer Portal before rendering
    createServiceAlias(fleetopsEngine, customerPortalEngine, [
        'leaflet-map-manager',
        'location',
        'movement-tracker',
        'leaflet-routing-control',
        'order-config-actions',
        'order-creation',
        'order-validation',
    ]);

    const menuService = universe.getService('universe/menu-service');
    const registryService = universe.getService('universe/registry-service');

    // Register customer orders from Fleet-Ops
    menuService.registerMenuItem(
        'customer-portal:sidebar',
        new MenuItem({
            title: 'Orders',
            route: 'customer-portal.portal.virtual',
            icon: 'boxes-packing',
            component: createEngineBoundComponent(customerPortalEngine, CustomerOrdersComponent),
        })
    );

    // Register registries
    registryService.register('customer-portal:admin-settings', '@fleetbase/customer-portal-engine', CustomerAdminSettingsComponent);

    // Flag fleetops setup complete
    customerPortalEngine._fleetopsSetupCompleted = true;
}

function createServiceAlias(sourceOwner, destOwner, serviceNames) {
    serviceNames.forEach((name) => {
        const key = `service:${name}`;
        if (destOwner.hasRegistration?.(key)) return;

        // alias the fully-wired instance
        const instance = sourceOwner.lookup?.(key);
        if (instance) {
            destOwner.register(key, instance, { instantiate: false });
            return;
        }

        // Faillback: only use factories for simple/stateless services
        const factory = sourceOwner.resolveRegistration?.(key);
        if (factory) {
            destOwner.register(key, factory);
            return;
        }

        debug(`[alias] missing ${key} on source; not aliased`);
    });
}

function createEngineBoundComponent(engineInstance, ComponentClass) {
    return class EngineBoundComponent extends ComponentClass {
        constructor(...args) {
            super(...args);
            // Ensure DI resolution happens within the engine
            setOwner(this, engineInstance);
        }
    };
}
