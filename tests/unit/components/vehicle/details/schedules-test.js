import Service from '@ember/service';
import { module, test } from 'qunit';
import { click, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import { setupRenderingTest } from 'dummy/tests/helpers';

class StoreStub extends Service {
    queries = [];

    query(modelName, params) {
        this.queries.push([modelName, params]);

        return Promise.resolve([]);
    }
}

class VehicleActionsStub extends Service {
    calls = [];

    scheduleMaintenance(...args) {
        this.calls.push(args);
        args[2].callback();
        return 'opened';
    }
}

class NotificationsStub extends Service {
    serverError() {}
}

module('Unit | Component | vehicle/details/schedules', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:store', StoreStub);
        this.owner.register('service:vehicle-actions', VehicleActionsStub);
        this.owner.register('service:notifications', NotificationsStub);
    });

    test('loads by the backend polymorphic type and reloads after modal creation', async function (assert) {
        const vehicle = { id: 'vehicle-uuid' };
        this.set('vehicle', vehicle);

        await render(hbs`<Vehicle::Details::Schedules @resource={{this.vehicle}} @vehicle={{this.vehicle}} />`);

        const store = this.owner.lookup('service:store');
        assert.deepEqual(store.queries[0], ['maintenance-schedule', { subject_uuid: 'vehicle-uuid', subject_type: 'fleet-ops:vehicle', sort: '-created_at' }]);

        await click('.vehicle-details-schedules button');

        const vehicleActions = this.owner.lookup('service:vehicle-actions');
        const [scheduledVehicle, options, saveOptions] = vehicleActions.calls[0];
        assert.strictEqual(scheduledVehicle, vehicle);
        assert.deepEqual(options, {});
        assert.false(saveOptions.refresh);

        assert.strictEqual(store.queries.length, 2, 'the vehicle schedule list reloads after save');
    });
});
