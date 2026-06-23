import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import Service from '@ember/service';

class IntlServiceStub extends Service {
    t(key) {
        return key;
    }
}

class SensorActionsStub extends Service {
    transition = { view() {} };
    controllerSearchTask = { perform() {} };
}

class DeviceActionsStub extends Service {
    transition = { view() {} };
}

module('Unit | Controller | connectivity/telematics/index/details/sensors', function (hooks) {
    setupTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:intl', IntlServiceStub);
        this.owner.register('service:sensor-actions', SensorActionsStub);
        this.owner.register('service:device-actions', DeviceActionsStub);
    });

    test('it exists', function (assert) {
        let controller = this.owner.lookup('controller:connectivity/telematics/index/details/sensors');
        assert.ok(controller);
    });

    test('sensor type and status filters use option label and value contracts', function (assert) {
        let controller = this.owner.lookup('controller:connectivity/telematics/index/details/sensors');
        let typeColumn = controller.columns.find((column) => column.label === 'Type');
        let statusColumn = controller.columns.find((column) => column.label === 'column.status');

        assert.strictEqual(typeColumn.filterComponent, 'filter/multi-option');
        assert.strictEqual(typeColumn.filterOptionLabel, 'label', 'type filter reads option labels explicitly');
        assert.strictEqual(typeColumn.filterOptionValue, 'value', 'type filter serializes option values explicitly');

        assert.strictEqual(statusColumn.filterComponent, 'filter/multi-option');
        assert.strictEqual(statusColumn.filterOptionLabel, 'label', 'status filter reads option labels explicitly');
        assert.strictEqual(statusColumn.filterOptionValue, 'value', 'status filter serializes option values explicitly');
    });
});
