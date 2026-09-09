import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import Service from '@ember/service';

class HostRouterStub extends Service {
    transitionTo() {
        return Promise.resolve();
    }
}

class MenuServiceStub extends Service {
    getMenuItems() {
        return [{ route: 'management.trailers.index.details.custom', label: 'Custom' }];
    }
}

module('Unit | Controller | management/trailers/index/details', function (hooks) {
    setupTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:universe/menu-service', MenuServiceStub);
        this.owner.register('service:host-router', HostRouterStub);
    });

    test('it exposes every Trailer details tab in order, followed by registered tabs', function (assert) {
        const controller = this.owner.lookup('controller:management/trailers/index/details');

        assert.deepEqual(
            controller.tabs.map((tab) => tab.route),
            [
                'management.trailers.index.details.index',
                'management.trailers.index.details.positions',
                'management.trailers.index.details.devices',
                'management.trailers.index.details.equipment',
                'management.trailers.index.details.connections',
                'management.trailers.index.details.schedules',
                'management.trailers.index.details.work-orders',
                'management.trailers.index.details.maintenance-history',
                'management.trailers.index.details.custom',
            ]
        );
        assert.deepEqual(
            controller.tabs.slice(0, 8).map((tab) => tab.label),
            ['Overview', 'Positions', 'Devices', 'Equipment', 'Towing history', 'Schedules', 'Work orders', 'Maintenance']
        );
    });

    test('it offers attach or detach depending on the towing state', function (assert) {
        const controller = this.owner.lookup('controller:management/trailers/index/details');
        const store = this.owner.lookup('service:store');

        controller.model = store.createRecord('trailer', { name: 'Reefer 12', attachment_state: 'detached' });
        let items = controller.actionButtons[1].items.map((item) => item.text).filter(Boolean);
        assert.true(items.includes('Attach to vehicle'));
        assert.false(items.includes('Detach from vehicle'));

        controller.model = store.createRecord('trailer', { name: 'Reefer 12', attachment_state: 'attached' });
        items = controller.actionButtons[1].items.map((item) => item.text).filter(Boolean);
        assert.true(items.includes('Detach from vehicle'));
        assert.false(items.includes('Attach to vehicle'));
    });
});
