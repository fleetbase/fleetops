import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, findAll, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

/**
 * Complements resource-identities-test.js with the label chain, status-tone, driver and plate
 * badge and click delegation variants of the vehicle identity cell.
 */

const COMPACT = '[data-test-vehicle-identity-compact]';
const DOT = '[data-test-resource-identity-status-dot]';
const BADGE = '[data-test-resource-identity-meta-badge]';
const STATUS = '[data-test-resource-identity-status-badge]';

function badges() {
    return findAll(BADGE).map((element) => element.textContent.trim());
}

module('Integration | Component | cell/vehicle-identity', function (hooks) {
    setupRenderingTest(hooks);

    test('labels fall back through displayName, display_name and name', async function (assert) {
        for (const [vehicle, expected] of [
            [{ displayName: 'Display Van' }, 'Display Van'],
            [{ display_name: 'Snake Van' }, 'Snake Van'],
            [{ name: 'Plain Van' }, 'Plain Van'],
        ]) {
            this.set('vehicle', vehicle);
            await render(hbs`<Cell::VehicleIdentity @row={{this.vehicle}} @column={{hash compact=true}} />`);
            assert.dom(COMPACT).includesText(expected, `compact ${expected}`);

            await render(hbs`<Cell::VehicleIdentity @row={{this.vehicle}} @column={{hash showStatus=false}} />`);
            assert.dom(this.element).includesText(expected, `full ${expected}`);
        }
    });

    test('compact status dot tones follow online, then the status map, then custom classes', async function (assert) {
        const cases = [
            [{ name: 'V', online: true }, {}, 'text-green-500'],
            [{ name: 'V', online: false }, {}, 'text-yellow-200'],
            [{ name: 'V', status: 'maintenance' }, {}, 'text-yellow-500'],
            [{ name: 'V', status: 'OUT_OF_SERVICE' }, {}, 'text-red-500'],
            [{ name: 'V', status: 'mystery' }, {}, 'text-gray-400'],
            [{ name: 'V' }, {}, 'text-gray-400'],
            [{ name: 'V', status: 'mystery' }, { statusToneMap: { mystery: 'text-purple-500' } }, 'text-purple-500'],
            [{ name: 'V', status: 'active' }, { statusToneClass: (value) => `custom-${value}` }, 'custom-active'],
        ];

        for (const [vehicle, column, expected] of cases) {
            this.set('vehicle', vehicle);
            this.set('column', { compact: true, ...column });
            await render(hbs`<Cell::VehicleIdentity @row={{this.vehicle}} @column={{this.column}} />`);
            assert.dom(DOT).hasClass(expected, `${JSON.stringify(vehicle)} -> ${expected}`);
        }

        await render(hbs`<Cell::VehicleIdentity @row={{this.vehicle}} @column={{hash compact=true showStatusDot=false}} />`);
        assert.dom(DOT).doesNotExist();

        await render(hbs`<Cell::VehicleIdentity @row={{this.vehicle}} @column={{hash compact=true showOnlineIndicator=false}} />`);
        assert.dom(DOT).doesNotExist();
    });

    test('the compact driver badge falls back through driver.displayName, driver.display_name, driver.name and driver_name', async function (assert) {
        for (const [vehicle, expected] of [
            [{ name: 'V', driver: { displayName: 'Driver A' } }, 'Driver A'],
            [{ name: 'V', driver: { display_name: 'Driver B' } }, 'Driver B'],
            [{ name: 'V', driver: { name: 'Driver C' } }, 'Driver C'],
            [{ name: 'V', driver_name: 'Driver D' }, 'Driver D'],
            [{ name: 'V' }, null],
        ]) {
            this.set('vehicle', vehicle);
            await render(hbs`<Cell::VehicleIdentity @row={{this.vehicle}} @column={{hash compact=true}} />`);
            if (expected) {
                assert.dom(BADGE).hasText(expected);
            } else {
                assert.dom(BADGE).doesNotExist();
            }
        }
    });

    test('the full identity shows the plate then the driver, and the status unless suppressed', async function (assert) {
        this.set('vehicle', { name: 'Full Van', status: 'active', plate_number: 'PLATE-1', driver: { name: 'Driver C' } });
        await render(hbs`<Cell::VehicleIdentity @row={{this.vehicle}} @column={{(hash)}} />`);
        assert.deepEqual(badges(), ['PLATE-1', 'Driver C']);
        assert.dom(STATUS).hasText('Active');

        await render(hbs`<Cell::VehicleIdentity @row={{this.vehicle}} @column={{hash showStatus=false}} />`);
        assert.dom(STATUS).doesNotExist();

        await render(hbs`<Cell::VehicleIdentity @row={{this.vehicle}} @column={{hash showStatusBadge=false}} />`);
        assert.dom(STATUS).doesNotExist();

        await render(hbs`<Cell::VehicleIdentity @row={{this.vehicle}} @column={{hash statusBadgeSize="sm" statusBadgeWrapperClass="custom-wrapper"}} />`);
        assert.dom('.custom-wrapper').exists();

        for (const [vehicle, expected] of [
            [{ name: 'V', call_sign: 'CS-1', vehicle_number: 'VN-1', driver_name: 'Driver D' }, ['CS-1', 'Driver D']],
            [{ name: 'V', vehicle_number: 'VN-1', public_id: 'vehicle_1', driver: { display_name: 'Driver B' } }, ['VN-1', 'Driver B']],
            [{ name: 'V', public_id: 'vehicle_1', driver: { displayName: 'Driver A' } }, ['vehicle_1', 'Driver A']],
            [{ name: 'V' }, []],
        ]) {
            this.set('vehicle', vehicle);
            await render(hbs`<Cell::VehicleIdentity @row={{this.vehicle}} @column={{hash showStatus=false}} />`);
            assert.deepEqual(badges(), expected, JSON.stringify(vehicle));
        }
    });

    test('a compact click reaches the cell handler and both column handlers with the vehicle', async function (assert) {
        const calls = [];
        this.set('vehicle', { name: 'V' });
        this.set('onClick', (resource) => calls.push(['onClick', resource]));
        this.set('column', { compact: true, onClick: (resource) => calls.push(['column.onClick', resource]), action: (resource) => calls.push(['column.action', resource]) });

        await render(hbs`<Cell::VehicleIdentity @row={{this.vehicle}} @column={{this.column}} @onClick={{this.onClick}} />`);
        await click(COMPACT);

        assert.deepEqual(
            calls.map(([name, resource]) => [name, resource === this.vehicle]),
            [
                ['onClick', true],
                ['column.onClick', true],
                ['column.action', true],
            ]
        );

        await render(hbs`<Cell::VehicleIdentity @row={{this.vehicle}} @column={{hash compact=true}} />`);
        await click(COMPACT);
        assert.strictEqual(calls.length, 3, 'no handlers, no calls');
    });

    test('the resource resolves through paths and the empty text shows otherwise', async function (assert) {
        this.set('row', { assignment: { vehicle: { name: 'Path Van' } } });

        await render(hbs`<Cell::VehicleIdentity @row={{this.row}} @column={{hash compact=true resourcePath="assignment.vehicle"}} />`);
        assert.dom(COMPACT).includesText('Path Van');

        this.set('column', { compact: true, resourcePath: (row) => row.assignment.vehicle });
        await render(hbs`<Cell::VehicleIdentity @row={{this.row}} @column={{this.column}} />`);
        assert.dom(COMPACT).includesText('Path Van');

        this.set('column', { resourcePath: 'assignment.none' });
        await render(hbs`<Cell::VehicleIdentity @row={{this.row}} @column={{this.column}} />`);
        assert.dom('[data-test-identity-empty-text]').hasText('-');

        this.set('column', { resourcePath: () => null, emptyText: 'No vehicle' });
        await render(hbs`<Cell::VehicleIdentity @row={{this.row}} @column={{this.column}} />`);
        assert.dom('[data-test-identity-empty-text]').hasText('No vehicle');
    });

    test('it renders without any column configuration', async function (assert) {
        this.set('vehicle', { name: 'Bare Van' });
        await render(hbs`<Cell::VehicleIdentity @row={{this.vehicle}} />`);
        assert.dom(this.element).includesText('Bare Van');
    });
});
