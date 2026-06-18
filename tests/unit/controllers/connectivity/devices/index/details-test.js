import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import Service from '@ember/service';

class MenuServiceStub extends Service {
    getMenuItems() {
        return [
            {
                route: 'connectivity.devices.index.details.virtual',
                label: 'Custom',
            },
        ];
    }
}

class IntlServiceStub extends Service {
    t(key) {
        return key;
    }
}

module('Unit | Controller | connectivity/devices/index/details', function (hooks) {
    setupTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:universe/menu-service', MenuServiceStub);
        this.owner.register('service:intl', IntlServiceStub);
    });

    test('it uses existing translation keys and preserves registered tabs', function (assert) {
        let controller = this.owner.lookup('controller:connectivity/devices/index/details');

        assert.deepEqual(
            controller.tabs.map((tab) => tab.label),
            ['common.overview', 'resource.vehicle', 'resource.sensors', 'resource.device-events', 'Custom']
        );
    });
});
