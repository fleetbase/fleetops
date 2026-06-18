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
});

function makePage(count, meta, prefix) {
    const records = Array.from({ length: count }, (_value, index) => ({ id: `${prefix}-${index + 1}` }));

    records.meta = meta;

    return Promise.resolve(records);
}
