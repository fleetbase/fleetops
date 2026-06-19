import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | cell/telematic-device', function (hooks) {
    setupRenderingTest(hooks);

    test('it renders an online bulb over the device image', async function (assert) {
        this.set('device', {
            displayName: 'Gateway 100',
            device_id: 'gw-100',
            is_online: true,
            connection_status: 'online',
        });
        this.set('column', {});

        await render(hbs`<Cell::TelematicDevice @row={{this.device}} @column={{this.column}} />`);

        assert.dom('[data-test-telematic-device-online-indicator]').exists();
        assert.dom('[data-test-telematic-device-online-indicator]').hasClass('text-green-500');
        assert.dom('[data-test-telematic-device-online-indicator]').hasClass('left-0');
        assert.dom('[data-test-telematic-device-online-indicator]').doesNotHaveClass('right-0');
        assert.dom('[data-test-telematic-device-image]').hasClass('rounded-sm');
        assert.dom('[data-test-telematic-device-image]').hasClass('border');
        assert.dom('[data-test-telematic-device-image]').hasClass('shadow-sm');
        assert.dom('[data-test-telematic-device-status-badge]').exists();
        assert.dom('[data-test-telematic-device-status-badge]').hasClass('online-status-badge');
        assert.dom('[data-test-telematic-device-status-badge]').hasClass('fleetops-device-status-badge');
        assert.dom('[data-test-telematic-device-status-badge]').hasText('Online');

        const statusBadge = this.element.querySelector('[data-test-telematic-device-status-badge]');
        const identifier = this.element.querySelector('[data-test-telematic-device-identifier]');

        assert.true(Boolean(statusBadge.compareDocumentPosition(identifier) & 4), 'status badge renders before the identifier');
    });

    test('it renders an offline bulb over the device image', async function (assert) {
        this.set('device', {
            displayName: 'Gateway 101',
            device_id: 'gw-101',
            is_online: false,
            connection_status: 'offline',
        });
        this.set('column', {});

        await render(hbs`<Cell::TelematicDevice @row={{this.device}} @column={{this.column}} />`);

        assert.dom('[data-test-telematic-device-online-indicator]').exists();
        assert.dom('[data-test-telematic-device-online-indicator]').hasClass('text-yellow-200');
        assert.dom('[data-test-telematic-device-online-indicator]').hasClass('left-0');
        assert.dom('[data-test-telematic-device-online-indicator]').doesNotHaveClass('right-0');
        assert.dom('[data-test-telematic-device-status-badge]').hasClass('offline-status-badge');
        assert.dom('[data-test-telematic-device-status-badge]').hasClass('fleetops-device-status-badge');
        assert.dom('[data-test-telematic-device-status-badge]').hasText('Offline');
    });
});
