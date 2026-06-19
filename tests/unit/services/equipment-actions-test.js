import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import Service from '@ember/service';

module('Unit | Service | equipment-actions', function (hooks) {
    setupTest(hooks);

    test('it creates new equipment with default status and currency', function (assert) {
        class CurrentUserStubService extends Service {
            currency = 'SGD';
        }

        this.owner.register('service:current-user', CurrentUserStubService);

        const service = this.owner.lookup('service:equipment-actions');
        const equipment = service.createNewInstance();

        assert.ok(service);
        assert.strictEqual(equipment.status, 'available');
        assert.strictEqual(equipment.currency, 'SGD');
    });
});
