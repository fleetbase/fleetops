import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';

module('Unit | Service | order-list-overlay', function (hooks) {
    setupTest(hooks);

    // TODO: Replace this with your real tests.
    test('it exists', function (assert) {
        let service = this.owner.lookup('service:order-list-overlay');
        assert.ok(service);
    });
});
