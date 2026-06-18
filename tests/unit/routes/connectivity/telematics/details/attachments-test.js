import Service from '@ember/service';
import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';

module('Unit | Route | connectivity/telematics/details/attachments', function (hooks) {
    setupTest(hooks);

    test('loads all device pages for the selected telematic connection', async function (assert) {
        assert.expect(7);

        class StoreStub extends Service {
            queries = [];

            query(modelName, params) {
                this.queries.push({ modelName, params });

                if (params.page === 1) {
                    return makePage(100, { total: 233, current_page: 1, last_page: 3, per_page: 100 }, 'page-1');
                }

                if (params.page === 2) {
                    return makePage(100, { total: 233, current_page: 2, last_page: 3, per_page: 100 }, 'page-2');
                }

                return makePage(33, { total: 233, current_page: 3, last_page: 3, per_page: 100 }, 'page-3');
            }

            findRecord() {
                throw new Error('vehicle should not be loaded without a filter');
            }
        }

        this.owner.register('service:store', StoreStub);

        const route = this.owner.lookup('route:connectivity/telematics/details/attachments');
        const store = this.owner.lookup('service:store');

        route.modelFor = () => ({ id: 'telematic_1' });

        const model = await route.model({ sort: '-updated_at' });

        assert.strictEqual(model.devices.length, 233, 'all pages are flattened into one device list');
        assert.strictEqual(model.meta.total, 233, 'total is preserved from pagination meta');
        assert.strictEqual(model.meta.loaded, 233, 'loaded count reflects the flattened list');
        assert.strictEqual(store.queries.length, 3, 'route queries until the final page');
        assert.true(
            store.queries.every((query) => query.modelName === 'device'),
            'route only queries devices'
        );
        assert.true(
            store.queries.every((query) => query.params.telematic_uuid === 'telematic_1'),
            'every page is scoped to the telematic'
        );
        assert.deepEqual(
            store.queries.map((query) => query.params.page),
            [1, 2, 3],
            'pages are requested in order'
        );
    });

    test('does not declare a mapping-state query param', function (assert) {
        const route = this.owner.lookup('route:connectivity/telematics/details/attachments');

        assert.notOk(route.queryParams.attachment_state, 'mapping-state query param is not part of the attachments workspace');
    });

    test('hydrates selected vehicle from the vehicle query param', async function (assert) {
        assert.expect(6);
        const selectedVehicle = { id: 'vehicle_1', displayName: 'Truck 100' };

        class StoreStub extends Service {
            findRecord(modelName, id) {
                assert.strictEqual(modelName, 'vehicle', 'vehicle model is loaded');
                assert.strictEqual(id, 'vehicle_1', 'selected vehicle id is loaded from the query param');

                return Promise.resolve(selectedVehicle);
            }

            query(modelName, params) {
                assert.strictEqual(modelName, 'device', 'devices are still loaded');
                assert.strictEqual(params.telematic_uuid, 'telematic_1', 'device load is scoped to the telematic');

                return makePage(1, { total: 1, current_page: 1, last_page: 1, per_page: 100 }, 'page-1');
            }
        }

        this.owner.register('service:store', StoreStub);

        const route = this.owner.lookup('route:connectivity/telematics/details/attachments');

        route.modelFor = () => ({ id: 'telematic_1' });

        const model = await route.model({ sort: '-updated_at', vehicle: 'vehicle_1' });

        assert.strictEqual(model.selectedVehicle, selectedVehicle, 'selected vehicle is exposed on the route model');

        const controller = {};
        route.setupController(controller, model);

        assert.strictEqual(controller.selectedVehicle, selectedVehicle, 'setupController assigns selected vehicle to the controller');
    });

    test('failed selected vehicle hydration does not fail device loading', async function (assert) {
        assert.expect(3);

        class StoreStub extends Service {
            findRecord() {
                return Promise.reject(new Error('Vehicle missing'));
            }

            query(modelName) {
                assert.strictEqual(modelName, 'device', 'devices are still loaded after vehicle hydration fails');

                return makePage(2, { total: 2, current_page: 1, last_page: 1, per_page: 100 }, 'page-1');
            }
        }

        this.owner.register('service:store', StoreStub);

        const route = this.owner.lookup('route:connectivity/telematics/details/attachments');

        route.modelFor = () => ({ id: 'telematic_1' });

        const model = await route.model({ sort: '-updated_at', vehicle: 'missing_vehicle' });

        assert.strictEqual(model.selectedVehicle, null, 'selected vehicle falls back to null');
        assert.strictEqual(model.devices.length, 2, 'device results are preserved');
    });
});

function makePage(count, meta, prefix) {
    const records = Array.from({ length: count }, (_value, index) => ({ id: `${prefix}-${index + 1}` }));

    records.meta = meta;

    return Promise.resolve(records);
}
