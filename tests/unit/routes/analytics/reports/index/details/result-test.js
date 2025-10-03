import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';

module('Unit | Route | analytics/reports/index/details/result', function (hooks) {
    setupTest(hooks);

    test('it exists', function (assert) {
        let route = this.owner.lookup('route:analytics/reports/index/details/result');
        assert.ok(route);
    });
});
