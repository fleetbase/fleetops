import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, findAll, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

/**
 * Complements resource-identities-test.js with the label chain, status-tone, identifier and
 * click delegation variants of the device identity cell.
 */

const COMPACT = '[data-test-device-identity-compact]';
const DOT = '[data-test-resource-identity-status-dot]';
const BADGE = '[data-test-resource-identity-meta-badge]';
const STATUS = '[data-test-resource-identity-status-badge]';

function badges() {
    return findAll(BADGE).map((element) => element.textContent.trim());
}

module('Integration | Component | cell/device-identity', function (hooks) {
    setupRenderingTest(hooks);

    test('labels fall back through displayName, display_name, name, device_id, imei and serial_number', async function (assert) {
        const cases = [
            [{ displayName: 'Display Name' }, 'Display Name'],
            [{ display_name: 'Snake Name' }, 'Snake Name'],
            [{ name: 'Plain Name' }, 'Plain Name'],
            [{ device_id: 'DEV-1' }, 'DEV-1'],
            [{ imei: 'IMEI-1' }, 'IMEI-1'],
            [{ serial_number: 'SER-1' }, 'SER-1'],
        ];

        for (const [device, expected] of cases) {
            this.set('device', device);
            await render(hbs`<Cell::DeviceIdentity @row={{this.device}} @column={{hash compact=true}} />`);
            assert.dom(COMPACT).includesText(expected, `compact ${JSON.stringify(device)}`);

            await render(hbs`<Cell::DeviceIdentity @row={{this.device}} @column={{hash showStatus=false}} />`);
            assert.dom(this.element).includesText(expected, `full ${JSON.stringify(device)}`);
        }
    });

    test('compact status dot tones follow is_online, then connection_status, then status, then custom classes', async function (assert) {
        const cases = [
            [{ name: 'D', is_online: true }, {}, 'text-green-500'],
            [{ name: 'D', is_online: false }, {}, 'text-yellow-200'],
            [{ name: 'D', connection_status: 'recently_offline' }, {}, 'text-yellow-500'],
            [{ name: 'D', status: 'ERROR' }, {}, 'text-red-500'],
            [{ name: 'D', status: 'mystery' }, {}, 'text-gray-400'],
            [{ name: 'D' }, {}, 'text-gray-400'],
            [{ name: 'D', status: 'mystery' }, { statusToneMap: { mystery: 'text-purple-500' } }, 'text-purple-500'],
            [{ name: 'D', status: 'online' }, { statusToneClass: (value) => `custom-${value}` }, 'custom-online'],
        ];

        for (const [device, column, expected] of cases) {
            this.set('device', device);
            this.set('column', { compact: true, ...column });
            await render(hbs`<Cell::DeviceIdentity @row={{this.device}} @column={{this.column}} />`);
            assert.dom(DOT).hasClass(expected, `${JSON.stringify(device)} -> ${expected}`);
        }

        await render(hbs`<Cell::DeviceIdentity @row={{this.device}} @column={{hash compact=true showStatusDot=false}} />`);
        assert.dom(DOT).doesNotExist();

        await render(hbs`<Cell::DeviceIdentity @row={{this.device}} @column={{hash compact=true showOnlineIndicator=false}} />`);
        assert.dom(DOT).doesNotExist();
    });

    test('the full identity shows the status from connection_status or status, unless suppressed', async function (assert) {
        this.set('device', { name: 'Dev', connection_status: 'online', status: 'active' });
        await render(hbs`<Cell::DeviceIdentity @row={{this.device}} @column={{(hash)}} />`);
        assert.dom(STATUS).hasText('Online');

        this.set('device', { name: 'Dev', status: 'inactive' });
        await render(hbs`<Cell::DeviceIdentity @row={{this.device}} @column={{(hash)}} />`);
        assert.dom(STATUS).hasText('Inactive');

        await render(hbs`<Cell::DeviceIdentity @row={{this.device}} @column={{hash showStatusBadge=false}} />`);
        assert.dom(STATUS).doesNotExist();

        await render(hbs`<Cell::DeviceIdentity @row={{this.device}} @column={{hash statusBadgeSize="sm" statusBadgeWrapperClass="custom-wrapper"}} />`);
        assert.dom('.custom-wrapper').exists();
    });

    test('the identifier badge falls back through imei, device_id, ident and serial_number', async function (assert) {
        const cases = [
            [{ name: 'Dev', imei: 'IMEI-9', device_id: 'DEV-9' }, 'IMEI-9'],
            [{ name: 'Dev', device_id: 'DEV-9', ident: 'ID-9' }, 'DEV-9'],
            [{ name: 'Dev', ident: 'ID-9', serial_number: 'SER-9' }, 'ID-9'],
            [{ name: 'Dev', serial_number: 'SER-9' }, 'SER-9'],
            [{ name: 'Dev' }, null],
        ];

        for (const [device, expected] of cases) {
            this.set('device', device);
            await render(hbs`<Cell::DeviceIdentity @row={{this.device}} @column={{hash showStatus=false}} />`);
            assert.deepEqual(badges(), expected ? [expected] : [], JSON.stringify(device));
        }
    });

    test('a compact click reaches the cell handler and both column handlers with the device', async function (assert) {
        const calls = [];
        this.set('device', { name: 'Dev' });
        this.set('onClick', (resource) => calls.push(['onClick', resource]));
        this.set('column', { compact: true, onClick: (resource) => calls.push(['column.onClick', resource]), action: (resource) => calls.push(['column.action', resource]) });

        await render(hbs`<Cell::DeviceIdentity @row={{this.device}} @column={{this.column}} @onClick={{this.onClick}} />`);
        await click(COMPACT);

        assert.deepEqual(
            calls.map(([name, resource]) => [name, resource === this.device]),
            [
                ['onClick', true],
                ['column.onClick', true],
                ['column.action', true],
            ]
        );

        await render(hbs`<Cell::DeviceIdentity @row={{this.device}} @column={{hash compact=true}} />`);
        await click(COMPACT);
        assert.strictEqual(calls.length, 3, 'no handlers, no calls');
    });

    test('the resource resolves through paths and the empty text shows otherwise', async function (assert) {
        this.set('row', { link: { device: { name: 'Linked Device' } } });

        await render(hbs`<Cell::DeviceIdentity @row={{this.row}} @column={{hash compact=true resourcePath="link.device"}} />`);
        assert.dom(COMPACT).includesText('Linked Device');

        this.set('column', { compact: true, resourcePath: (row) => row.link.device });
        await render(hbs`<Cell::DeviceIdentity @row={{this.row}} @column={{this.column}} />`);
        assert.dom(COMPACT).includesText('Linked Device');

        this.set('column', { resourcePath: 'link.none' });
        await render(hbs`<Cell::DeviceIdentity @row={{this.row}} @column={{this.column}} />`);
        assert.dom('[data-test-identity-empty-text]').hasText('-');

        this.set('column', { resourcePath: () => undefined, emptyText: 'No device' });
        await render(hbs`<Cell::DeviceIdentity @row={{this.row}} @column={{this.column}} />`);
        assert.dom('[data-test-identity-empty-text]').hasText('No device');
    });

    test('it renders without any column configuration', async function (assert) {
        this.set('device', { name: 'Bare Device' });
        await render(hbs`<Cell::DeviceIdentity @row={{this.device}} />`);
        assert.dom(this.element).includesText('Bare Device');
    });
});
