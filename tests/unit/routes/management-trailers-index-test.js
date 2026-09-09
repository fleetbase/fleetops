import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import Service from '@ember/service';

class StoreStubService extends Service {
    queries = [];

    query(modelName, params) {
        this.queries.push({ modelName, params });
        return Promise.resolve([]);
    }
}

module('Unit | Route | management/trailers/index', function (hooks) {
    setupTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:store', StoreStubService);
    });

    test('it queries first-class trailers with the route params and refreshes on every table filter', async function (assert) {
        const route = this.owner.lookup('route:management/trailers/index');
        const store = this.owner.lookup('service:store');
        const params = {
            page: 2,
            limit: 25,
            sort: '-updated_at',
            trailer_type: 'reefer',
            attachment_state: 'attached',
            connectivity_status: 'online',
            vehicle: 'vehicle_test',
        };

        await route.model(params);

        assert.deepEqual(store.queries, [{ modelName: 'trailer', params }]);

        const filterParams = [
            'page',
            'limit',
            'sort',
            'query',
            'public_id',
            'name',
            'code',
            'trailer_type',
            'status',
            'attachment_state',
            'vehicle',
            'connectivity_status',
            'trailer_make',
            'trailer_model',
            'trailer_year',
            'plate_number',
            'vin',
            'serial_number',
            'vendor',
            'ownership_type',
            'refrigerated',
            'last_online_at',
            'created_at',
            'updated_at',
        ];

        for (const key of filterParams) {
            assert.deepEqual(route.queryParams[key], { refreshModel: true }, `${key} refreshes the Trailer model`);
        }
    });

    test('the index controller declares every filterable column parameter as a query param', function (assert) {
        const route = this.owner.lookup('route:management/trailers/index');
        const controller = this.owner.lookup('controller:management/trailers/index');
        const declared = new Set(controller.queryParams);

        for (const column of controller.columns) {
            const param = column.filterParam ?? column.valuePath;

            if (column.filterable) {
                assert.true(declared.has(param), `filter "${param}" for column "${column.label}" is a declared query param`);
                assert.deepEqual(route.queryParams[param], { refreshModel: true }, `filter "${param}" refreshes the model`);
            }
        }
    });
});
