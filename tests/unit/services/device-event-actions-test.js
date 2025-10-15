import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';

module('Unit | Service | device-event-actions', function (hooks) {
    setupTest(hooks);

    // TODO: Replace this with your real tests.
    test('it exists', function (assert) {
        let service = this.owner.lookup('service:device-event-actions');
        assert.ok(service);
    });
});
