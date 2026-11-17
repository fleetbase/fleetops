import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';

module('Unit | Helper | entity-size and entity-weight', function (hooks) {
    setupTest(hooks);

    test('they read size and weight with units, or fall back', function (assert) {
        const size = this.owner.factoryFor('helper:entity-size').class;
        const weight = this.owner.factoryFor('helper:entity-weight').class;
        const entity = { length: 120, width: 80, height: 14.4, dimensions_unit: 'cm', weight: 2300, weight_unit: 'kg' };

        assert.strictEqual(size.compute([entity, 'No size']), '120 × 80 × 14.4 cm');
        assert.strictEqual(weight.compute([entity, 'No weight']), `${(2300).toLocaleString()} kg`);
        assert.strictEqual(size.compute([{ length: 1, width: 2 }, 'No size']), 'No size');
        assert.strictEqual(weight.compute([{}, 'No weight']), 'No weight');
    });
});
