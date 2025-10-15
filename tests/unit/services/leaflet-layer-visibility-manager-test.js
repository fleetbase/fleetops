import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';

module('Unit | Service | leaflet-layer-visibility-manager', function (hooks) {
    setupTest(hooks);

    // TODO: Replace this with your real tests.
    test('it exists', function (assert) {
        let service = this.owner.lookup('service:leaflet-layer-visibility-manager');
        assert.ok(service);
    });
});
