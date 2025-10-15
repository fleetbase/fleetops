import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';

module('Unit | Route | maintenance/equipment/index/edit', function (hooks) {
    setupTest(hooks);

    test('it exists', function (assert) {
        let route = this.owner.lookup('route:maintenance/equipment/index/edit');
        assert.ok(route);
    });
});
