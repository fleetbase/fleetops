import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';

module('Unit | Route | management/places/index/details/documents', function (hooks) {
    setupTest(hooks);

    test('it exists', function (assert) {
        let route = this.owner.lookup('route:management/places/index/details/documents');
        assert.ok(route);
    });
});
