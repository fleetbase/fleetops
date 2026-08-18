import Service from '@ember/service';
import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import MaintenanceSchedulesIndexEditController from '@fleetbase/fleetops-engine/controllers/maintenance/schedules/index/edit';

class HostRouterStub extends Service {
    refresh() {
        return Promise.resolve();
    }

    transitionTo() {
        return Promise.resolve();
    }
}

class IntlStub extends Service {
    calls = [];

    t(key, options) {
        this.calls.push([key, options]);
        return key === 'resource.maintenance-schedule' ? 'Maintenance Schedule' : 'Schedule updated';
    }
}

class NotificationsStub extends Service {
    messages = [];

    success(message) {
        this.messages.push(message);
    }

    serverError() {}
}

module('Unit | Controller | maintenance/schedules/index/edit', function (hooks) {
    setupTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('controller:maintenance/schedules/index/edit', MaintenanceSchedulesIndexEditController);
        this.owner.register('service:host-router', HostRouterStub);
        this.owner.register('service:intl', IntlStub);
        this.owner.register('service:notifications', NotificationsStub);
    });

    test('the update success message includes the schedule name required by the translation', function (assert) {
        const controller = this.owner.lookup('controller:maintenance/schedules/index/edit');
        const schedule = { name: 'Vehicle Service' };

        controller.notifyUpdateSuccess(schedule);

        const intl = this.owner.lookup('service:intl');
        assert.deepEqual(intl.calls.at(-1), ['common.resource-updated-success', { resource: 'Maintenance Schedule', resourceName: 'Vehicle Service' }]);
    });
});
