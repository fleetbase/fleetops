import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';

module('Unit | Service | leaflet-routing-control', function (hooks) {
    setupTest(hooks);

    // The console hands the universe its application instance while booting engines; this service
    // registers into the application registry through it, so the dummy app needs the same wiring.
    hooks.beforeEach(function () {
        this.owner.lookup('service:universe').setApplicationInstance(this.owner);
    });

    // TODO: Replace this with your real tests.
    test('it exists', function (assert) {
        let service = this.owner.lookup('service:leaflet-routing-control');
        assert.ok(service);
    });
});
