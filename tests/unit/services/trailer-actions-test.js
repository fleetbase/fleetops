import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';

module('Unit | Service | trailer-actions', function (hooks) {
    setupTest(hooks);

    test('it creates a first class Trailer with stable lifecycle defaults', function (assert) {
        const service = this.owner.lookup('service:trailer-actions');
        const trailer = service.createNewInstance();

        assert.strictEqual(trailer.status, 'available');
        assert.strictEqual(trailer.asset_class, 'trailer');
        assert.strictEqual(typeof service.transition.create, 'function');
    });
});
