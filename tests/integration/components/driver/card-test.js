import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';

class DriverActionsServiceStub extends Service {
    transition = {
        view() {},
        edit() {},
    };

    delete() {}
}

module('Integration | Component | driver/card', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:driver-actions', DriverActionsServiceStub);
        this.set('driver', {
            name: 'Test Driver',
            phone: '+1 555 0100',
            public_id: 'driver_test',
            photo_url: null,
            online: true,
            status: 'available',
            updatedAt: 'Today',
            vehicle_assigned: {
                display_name: 'Truck 12',
            },
        });
    });

    test('it renders a full-bleed photo, header online indicator, and status badge', async function (assert) {
        await render(hbs`<Driver::Card @resource={{this.driver}} />`);

        assert.dom('[data-test-driver-name]').hasText('Test Driver');
        assert.dom('[data-test-driver-online-indicator]').exists('online indicator renders beside the driver name');
        assert.dom('[data-test-driver-online-indicator]').hasClass('text-green-500');
        assert.dom('[data-test-driver-photo]').hasClass('w-full');
        assert.dom('[data-test-driver-photo]').hasClass('h-full');
        assert.dom('[data-test-driver-photo]').hasClass('rounded-none');
        assert.dom('[data-test-driver-photo]').doesNotHaveClass('rounded-full');
        assert.dom('[data-test-driver-status-badge]').hasClass('available-status-badge');
        assert.dom('[data-test-driver-status-badge]').hasText('available');
    });
});
