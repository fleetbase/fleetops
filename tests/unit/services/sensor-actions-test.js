import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import Service from '@ember/service';

class IntlServiceStub extends Service {
    t(key) {
        return key;
    }
}

class ResourceContextPanelStub extends Service {
    open(config) {
        return config;
    }
}

module('Unit | Service | sensor-actions', function (hooks) {
    setupTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:intl', IntlServiceStub);
        this.owner.register('service:resource-context-panel', ResourceContextPanelStub);
    });

    test('it exists', function (assert) {
        let service = this.owner.lookup('service:sensor-actions');
        assert.ok(service);
    });

    test('panel.view uses the sensor panel header', function (assert) {
        let service = this.owner.lookup('service:sensor-actions');
        let config = service.panel.view({ id: 'sensor_1', name: 'Fuel Sensor' });

        assert.strictEqual(config.header, 'sensor/panel-header');
        assert.deepEqual(
            config.tabs.map((tab) => tab.key),
            ['overview']
        );
    });
});
