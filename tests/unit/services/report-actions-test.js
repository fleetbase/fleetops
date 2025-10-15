import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';

module('Unit | Service | report-actions', function (hooks) {
    setupTest(hooks);

    // TODO: Replace this with your real tests.
    test('it exists', function (assert) {
        let service = this.owner.lookup('service:report-actions');
        assert.ok(service);
    });
});
