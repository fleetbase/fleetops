import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';

module('Unit | Controller | connectivity/telematics/index', function (hooks) {
    setupTest(hooks);

    test('it exists', function (assert) {
        let controller = this.owner.lookup('controller:connectivity/telematics/index');
        assert.ok(controller);
    });

    test('the status column renders through the shared telematic status cell', function (assert) {
        let controller = this.owner.lookup('controller:connectivity/telematics/index');
        let statusColumn = controller.columns.find((column) => column.valuePath === 'status');

        assert.strictEqual(statusColumn.cellComponent, 'cell/telematic-status');
    });
});
