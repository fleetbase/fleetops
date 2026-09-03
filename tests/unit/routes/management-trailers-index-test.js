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

    test('it loads first class trailers and refreshes all table filters', async function (assert) {
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
        for (const key of ['page', 'limit', 'sort', 'query', 'trailer_type', 'status', 'attachment_state', 'vehicle', 'connectivity_status', 'vendor']) {
            assert.deepEqual(route.queryParams[key], { refreshModel: true }, `${key} refreshes the Trailer model`);
        }
    });

    test('Trailer navigation is registered immediately after Vehicles', function (assert) {
        const source = this.owner.resolveRegistration('component:layout/fleet-ops-sidebar').toString();
        const vehicles = source.indexOf("this.createItem('menu.vehicles'");
        const trailers = source.indexOf("this.createItem('menu.trailers'");
        const fleets = source.indexOf("this.createItem('menu.fleets'");

        assert.true(vehicles >= 0, 'Vehicles navigation exists');
        assert.true(trailers > vehicles, 'Trailers follows Vehicles');
        assert.true(fleets > trailers, 'Trailers appears before Fleets');
    });
});
