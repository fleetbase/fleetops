import Service from '@ember/service';
import FleetsIndexRoute from '@fleetbase/fleetops-engine/routes/management/fleets/index';
import FleetEditRoute from '@fleetbase/fleetops-engine/routes/management/fleets/index/edit';
import FleetDetailsRoute from '@fleetbase/fleetops-engine/routes/management/fleets/index/details';
import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';

class StoreStub extends Service {
    calls = [];

    query(modelName, params) {
        this.calls.push({ method: 'query', modelName, params });
        return [];
    }

    queryRecord(modelName, params) {
        this.calls.push({ method: 'queryRecord', modelName, params });
        return {};
    }
}

class EmptyServiceStub extends Service {}

module('Unit | Route | management/fleets relation expansions', function (hooks) {
    setupTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:store', StoreStub);
        this.owner.register('service:abilities', EmptyServiceStub);
        this.owner.register('service:hostRouter', EmptyServiceStub);
        this.owner.register('service:intl', EmptyServiceStub);
        this.owner.register('service:notifications', EmptyServiceStub);
        this.owner.register('route:test-fleets-index', FleetsIndexRoute);
        this.owner.register('route:test-fleet-edit', FleetEditRoute);
        this.owner.register('route:test-fleet-details', FleetDetailsRoute);
    });

    test('fleet routes request real Eloquent relationship names', function (assert) {
        const indexRoute = this.owner.lookup('route:test-fleets-index');
        const editRoute = this.owner.lookup('route:test-fleet-edit');
        const detailsRoute = this.owner.lookup('route:test-fleet-details');
        const store = this.owner.lookup('service:store');

        indexRoute.model({ page: 1 });
        editRoute.model({ public_id: 'fleet_parent' });
        detailsRoute.model({ public_id: 'fleet_parent' });

        assert.deepEqual(store.calls[0].params.with, ['parentFleet', 'serviceArea', 'zone'], 'index route uses Eloquent relation names');
        assert.deepEqual(store.calls[1].params.with, ['parentFleet', 'serviceArea', 'zone'], 'edit route uses Eloquent relation names');
        assert.deepEqual(
            store.calls[2].params.with,
            ['parentFleet', 'serviceArea', 'zone', 'subFleets', 'drivers', 'vehicles'],
            'details route uses Eloquent relation names including subFleets'
        );
    });
});
