import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';

module('Unit | Route | management/vendors/integrated/edit', function (hooks) {
    setupTest(hooks);

    test('it exists', function (assert) {
        let route = this.owner.lookup('route:management/vendors/integrated/edit');
        assert.ok(route);
    });
});
