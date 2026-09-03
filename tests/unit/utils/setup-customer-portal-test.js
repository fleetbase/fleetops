import { module, test } from 'qunit';
import setupCustomerPortal from '@fleetbase/fleetops-engine/utils/setup-customer-portal';
import CustomerAdminSettingsComponent from '@fleetbase/fleetops-engine/components/customer/admin-settings';

const ENGINE = '@fleetbase/customer-portal-engine';

function fakeUniverse({ installed = true, loaded = false, engine = null } = {}) {
    const registered = [];
    const loadedCallbacks = [];
    const extensionManager = {
        isInstalled: (name) => name === ENGINE && installed,
        isEngineLoaded: (name) => name === ENGINE && loaded,
        getEngineInstance: (name) => (name === ENGINE ? engine : null),
    };
    const registryService = {
        register: (...args) => registered.push(args),
    };

    return {
        registered,
        loadedCallbacks,
        getService(name) {
            return { 'universe/extension-manager': extensionManager, 'universe/registry-service': registryService }[name];
        },
        onEngineLoaded(name, callback) {
            loadedCallbacks.push([name, callback]);
        },
    };
}

module('Unit | Utility | setup-customer-portal', function () {
    test('it does nothing when the customer portal is not installed', function (assert) {
        const universe = fakeUniverse({ installed: false });

        assert.strictEqual(setupCustomerPortal({}, universe), undefined);
        assert.deepEqual(universe.registered, []);
        assert.deepEqual(universe.loadedCallbacks, []);
    });

    test('it registers the admin settings on an already loaded portal engine, once', function (assert) {
        const engine = {};
        const universe = fakeUniverse({ loaded: true, engine });

        setupCustomerPortal({}, universe);
        setupCustomerPortal({}, universe);

        assert.deepEqual(universe.registered, [['customer-portal:admin-settings', ENGINE, CustomerAdminSettingsComponent]], 'the second call sees the completed flag');
        assert.true(engine._fleetopsSetupCompleted);
        assert.deepEqual(universe.loadedCallbacks, []);
    });

    test('it waits for the portal engine when it is not loaded or has no instance yet', function (assert) {
        for (const universe of [fakeUniverse({ loaded: false }), fakeUniverse({ loaded: true, engine: null })]) {
            const engine = {};
            setupCustomerPortal({}, universe);

            assert.deepEqual(universe.registered, []);
            assert.strictEqual(universe.loadedCallbacks.length, 1);
            assert.strictEqual(universe.loadedCallbacks[0][0], ENGINE);

            universe.loadedCallbacks[0][1](engine);
            assert.strictEqual(universe.registered.length, 1, 'setup runs once the engine loads');
            assert.true(engine._fleetopsSetupCompleted);
        }
    });
});
