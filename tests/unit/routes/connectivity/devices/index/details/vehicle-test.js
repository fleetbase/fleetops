import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import Service from '@ember/service';

module('Unit | Route | connectivity/devices/index/details/vehicle', function (hooks) {
    setupTest(hooks);

    test('it exists', function (assert) {
        let route = this.owner.lookup('route:connectivity/devices/index/details/vehicle');
        assert.ok(route);
    });

    test('model returns device with latest attached vehicle positions', async function (assert) {
        const device = {
            id: 'device_1',
            attachable: { id: 'vehicle_1' },
        };
        const positions = [{ id: 'position_1' }];

        class StoreStub extends Service {
            query(modelName, params) {
                assert.strictEqual(modelName, 'position', 'positions are queried');
                assert.deepEqual(params, { subject_uuid: 'vehicle_1', sort: '-created_at', limit: 5 }, 'latest vehicle positions are requested');

                return Promise.resolve(positions);
            }
        }

        this.owner.register('service:store', StoreStub);

        const route = this.owner.lookup('route:connectivity/devices/index/details/vehicle');
        route.modelFor = () => device;

        assert.deepEqual(await route.model(), { device, positions }, 'route model contains device and latest positions');
    });
});
