import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';

module('Unit | Route | maintenance/parts/index/edit', function (hooks) {
    setupTest(hooks);

    test('it exists', function (assert) {
        let route = this.owner.lookup('route:maintenance/parts/index/edit');
        assert.ok(route);
    });
});
