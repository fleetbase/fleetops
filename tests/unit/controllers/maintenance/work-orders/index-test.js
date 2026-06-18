import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';

module('Unit | Controller | maintenance/work-orders/index', function (hooks) {
    setupTest(hooks);

    test('it exists', function (assert) {
        let controller = this.owner.lookup('controller:maintenance/work-orders/index');
        assert.ok(controller);
    });

    test('it exposes category as a query param and filterable column', function (assert) {
        let controller = this.owner.lookup('controller:maintenance/work-orders/index');
        let categoryColumn = controller.columns.find((column) => column.valuePath === 'category');

        assert.true(controller.queryParams.includes('category'));
        assert.ok(categoryColumn);
        assert.true(categoryColumn.filterable);
        assert.strictEqual(categoryColumn.filterParam, 'category');
        assert.strictEqual(categoryColumn.filterComponent, 'filter/select');
        assert.strictEqual(categoryColumn.filterOptionValue, 'value');
        assert.ok(categoryColumn.filterOptions.find((option) => option.value === 'preventive_maintenance'));
    });
});
