import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import Service from '@ember/service';

class IntlServiceStub extends Service {
    t(key) {
        return key;
    }
}

class MenuServiceStub extends Service {
    getMenuItems() {
        return [
            {
                label: 'Custom',
                component: 'device/details/custom',
            },
            {
                label: 'Route only',
                route: 'connectivity.devices.index.details.custom',
            },
        ];
    }
}

class ResourceContextPanelStub extends Service {
    open(config) {
        this.config = config;
        return config;
    }
}

module('Unit | Service | device-actions', function (hooks) {
    setupTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:intl', IntlServiceStub);
        this.owner.register('service:universe/menu-service', MenuServiceStub);
        this.owner.register('service:resource-context-panel', ResourceContextPanelStub);
    });

    test('it exists', function (assert) {
        let service = this.owner.lookup('service:device-actions');
        assert.ok(service);
    });

    test('panel.view opens overview, vehicle, sensors, events, and compatible custom tabs', function (assert) {
        let service = this.owner.lookup('service:device-actions');
        let config = service.panel.view({ id: 'device_1', name: 'Device 1' });

        assert.strictEqual(config.header, 'device/panel-header');
        assert.deepEqual(
            config.tabs.map((tab) => tab.component),
            ['device/details', 'device/panel-tabs/vehicle', 'device/panel-tabs/sensors', 'device/panel-tabs/events', 'device/details/custom']
        );

        assert.deepEqual(
            config.tabs.slice(0, 4).map((tab) => tab.key),
            ['overview', 'vehicle', 'sensors', 'events']
        );
    });

    test('panel.view ignores missing device resources', function (assert) {
        let service = this.owner.lookup('service:device-actions');
        let result = service.panel.view();

        assert.strictEqual(result, undefined);
    });
});
