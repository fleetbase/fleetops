import CustomerOrdersComponent from '../components/customer/orders';
import CustomerAdminSettingsComponent from '../components/customer/admin-settings';
import { setOwner } from '@ember/application';
import { debug } from '@ember/debug';

export default function setupCustomerPortal(app, engine, universe) {
    if (!customerPortalInstalled(app)) return;

    universe.afterBoot(function (u) {
        const portal = u.getEngineInstance('@fleetbase/customer-portal-engine');
        if (!portal) {
            debug('Could not resolve @fleetbase/customer-portal-engine');
            return;
        }

        // Alias FleetOps services into Customer Portal before rendering
        createServiceAlias(engine, portal, ['leaflet-map-manager', 'location', 'movement-tracker', 'leaflet-routing-control', 'order-config-actions', 'order-creation', 'order-validation']);

        // Now it’s safe to wire menus + renderables that might use those services
        u.registerMenuItems('customer-portal:sidebar', [
            u._createMenuItem('Orders', 'customer-portal.portal.virtual', {
                icon: 'boxes-packing',
                component: createEngineBoundComponent(portal, CustomerOrdersComponent),
            }),
        ]);

        u.registerRenderableComponent('@fleetbase/customer-portal-engine', 'customer-portal:admin-settings', CustomerAdminSettingsComponent);
    });
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

function customerPortalInstalled(app) {
    const extensions = app.extensions ?? [];
    return extensions.find(({ name }) => name === '@fleetbase/customer-portal-engine');
}
