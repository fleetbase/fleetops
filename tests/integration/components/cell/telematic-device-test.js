import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, render } from '@ember/test-helpers';
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

    test('the name and identifier fall back through every known field', async function (assert) {
        const cases = [
            [{ display_name: 'Snake Name', device_id: 'DEV-1' }, 'Snake Name', 'DEV-1'],
            [{ name: 'Plain Name', internal_id: 'INT-1' }, 'Plain Name', 'INT-1'],
            [{ device_id: 'DEV-2', serial_number: 'SER-2' }, 'DEV-2', 'DEV-2'],
            [{ imei: 'IMEI-3', public_id: 'device_3' }, 'IMEI-3', 'IMEI-3'],
            [{ serial_number: 'SER-4' }, 'SER-4', 'SER-4'],
            [{ public_id: 'device_5' }, null, 'device_5'],
        ];

        for (const [device, name, identifier] of cases) {
            this.set('device', device);
            this.set('column', {});
            await render(hbs`<Cell::TelematicDevice @row={{this.device}} @column={{this.column}} />`);
            assert.dom('[data-test-telematic-device-identifier]').hasText(identifier, JSON.stringify(device));
            if (name) {
                assert.dom('.font-semibold').hasText(name, JSON.stringify(device));
            }
        }
    });

    test('an online device without a connection status is reported online', async function (assert) {
        this.set('device', { name: 'Dev', is_online: true });
        this.set('column', {});

        await render(hbs`<Cell::TelematicDevice @row={{this.device}} @column={{this.column}} />`);

        assert.dom('[data-test-telematic-device-status-badge]').includesText('Online');
        assert.dom('[data-test-telematic-device-online-indicator]').hasClass('text-green-500');
    });

    test('clicking the device delegates to the cell handler and both column handlers', async function (assert) {
        const calls = [];
        this.set('device', { name: 'Dev' });
        this.set('column', { action: (device) => calls.push(['action', device]), onClick: (device) => calls.push(['column.onClick', device]) });
        this.set('onClick', (device) => calls.push(['onClick', device]));

        await render(hbs`<Cell::TelematicDevice @row={{this.device}} @column={{this.column}} @onClick={{this.onClick}} />`);
        await click('button');

        assert.deepEqual(
            calls.map(([name, device]) => [name, device === this.device]),
            [
                ['onClick', true],
                ['action', true],
                ['column.onClick', true],
            ]
        );

        this.set('column', {});
        await render(hbs`<Cell::TelematicDevice @row={{this.device}} @column={{this.column}} />`);
        await click('button');
        assert.strictEqual(calls.length, 3, 'no handlers, no calls');
    });
});
