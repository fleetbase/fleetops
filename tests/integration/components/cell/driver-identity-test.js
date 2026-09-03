import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, findAll, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

/**
 * Complements resource-identities-test.js (which covers the default full and compact renders)
 * with the label, status-tone, assigned-vehicle and click delegation variants.
 */

const COMPACT = '[data-test-driver-identity-compact]';
const DOT = '[data-test-resource-identity-status-dot]';
const BADGE = '[data-test-resource-identity-meta-badge]';

function badges() {
    return findAll(BADGE).map((element) => element.textContent.trim());
}

module('Integration | Component | cell/driver-identity', function (hooks) {
    setupRenderingTest(hooks);

    test('compact labels fall back from name to displayName to display_name', async function (assert) {
        this.set('driver', { displayName: 'Display Name Driver' });
        await render(hbs`<Cell::DriverIdentity @row={{this.driver}} @column={{hash compact=true}} />`);
        assert.dom(COMPACT).includesText('Display Name Driver');

        this.set('driver', { display_name: 'Snake Case Driver' });
        await render(hbs`<Cell::DriverIdentity @row={{this.driver}} @column={{hash compact=true}} />`);
        assert.dom(COMPACT).includesText('Snake Case Driver');
    });

    test('compact status dot tones follow the online flag, then the status map, then a custom class', async function (assert) {
        const cases = [
            [{ name: 'D', online: true }, {}, 'text-green-500'],
            [{ name: 'D', online: false }, {}, 'text-yellow-200'],
            [{ name: 'D', status: 'busy' }, {}, 'text-yellow-500'],
            [{ name: 'D', status: 'SUSPENDED' }, {}, 'text-red-500'],
            [{ name: 'D', status: 'mystery' }, {}, 'text-gray-400'],
            [{ name: 'D' }, {}, 'text-gray-400'],
            [{ name: 'D', status: 'mystery' }, { statusToneMap: { mystery: 'text-purple-500' } }, 'text-purple-500'],
            [{ name: 'D', status: 'busy' }, { statusToneClass: (value) => `custom-${value}` }, 'custom-busy'],
        ];

        for (const [driver, column, expected] of cases) {
            this.set('driver', driver);
            this.set('column', { compact: true, ...column });
            await render(hbs`<Cell::DriverIdentity @row={{this.driver}} @column={{this.column}} />`);
            assert.dom(DOT).hasClass(expected, `${JSON.stringify(driver)} with ${Object.keys(column).join(',') || 'defaults'} -> ${expected}`);
        }
    });

    test('the compact status dot can be hidden by either column flag', async function (assert) {
        this.set('driver', { name: 'D', online: true });

        await render(hbs`<Cell::DriverIdentity @row={{this.driver}} @column={{hash compact=true showStatusDot=false}} />`);
        assert.dom(DOT).doesNotExist();

        await render(hbs`<Cell::DriverIdentity @row={{this.driver}} @column={{hash compact=true showOnlineIndicator=false}} />`);
        assert.dom(DOT).doesNotExist();
    });

    test('the compact assigned vehicle label comes from a column function, value, path or the driver', async function (assert) {
        this.set('driver', { name: 'D', vehicle: { display_name: 'Driver Vehicle' }, vehicle_name: 'Driver Vehicle Name', vehicle_uuid_label: 'From Driver Path' });
        this.set('row', { fleet_vehicle: 'From Row Path' });

        this.set('column', { compact: true, assignedVehicleLabel: (driver, row) => `${driver.name}/${row.fleet_vehicle}` });
        await render(hbs`<Cell::DriverIdentity @row={{this.row}} @value={{this.driver}} @column={{this.column}} />`);
        assert.dom(BADGE).hasText('D/From Row Path');

        this.set('column', { compact: true, assignedVehicleLabel: 'Fixed Label' });
        await render(hbs`<Cell::DriverIdentity @row={{this.row}} @value={{this.driver}} @column={{this.column}} />`);
        assert.dom(BADGE).hasText('Fixed Label');

        this.set('column', { compact: true, assignedVehiclePath: 'fleet_vehicle' });
        await render(hbs`<Cell::DriverIdentity @row={{this.row}} @value={{this.driver}} @column={{this.column}} />`);
        assert.dom(BADGE).hasText('From Row Path');

        this.set('column', { compact: true, assignedVehiclePath: 'vehicle_uuid_label' });
        await render(hbs`<Cell::DriverIdentity @row={{this.row}} @value={{this.driver}} @column={{this.column}} />`);
        assert.dom(BADGE).hasText('From Driver Path', 'a path missing on the row is read from the driver');

        this.set('column', { compact: true });
        await render(hbs`<Cell::DriverIdentity @row={{this.row}} @value={{this.driver}} @column={{this.column}} />`);
        assert.dom(BADGE).hasText('Driver Vehicle', 'vehicle.display_name before vehicle_name');

        this.set('driver', { name: 'D', vehicle_name: 'Only Vehicle Name' });
        await render(hbs`<Cell::DriverIdentity @row={{this.row}} @value={{this.driver}} @column={{this.column}} />`);
        assert.dom(BADGE).hasText('Only Vehicle Name');

        this.set('driver', { name: 'D' });
        await render(hbs`<Cell::DriverIdentity @row={{this.row}} @value={{this.driver}} @column={{this.column}} />`);
        assert.dom(BADGE).doesNotExist();
    });

    test('a compact click reaches the cell handler and both column handlers with the driver', async function (assert) {
        const calls = [];
        this.set('driver', { name: 'D' });
        this.set('onClick', (resource, event) => calls.push(['onClick', resource, event?.type]));
        this.set('column', {
            compact: true,
            onClick: (resource, event) => calls.push(['column.onClick', resource, event?.type]),
            action: (resource, event) => calls.push(['column.action', resource, event?.type]),
        });

        await render(hbs`<Cell::DriverIdentity @row={{this.driver}} @column={{this.column}} @onClick={{this.onClick}} />`);
        await click(COMPACT);

        assert.deepEqual(
            calls.map(([name, resource, type]) => [name, resource === this.driver, type]),
            [
                ['onClick', true, 'click'],
                ['column.onClick', true, 'click'],
                ['column.action', true, 'click'],
            ]
        );
    });

    test('a compact click with no handlers is a no-op', async function (assert) {
        this.set('driver', { name: 'D' });
        await render(hbs`<Cell::DriverIdentity @row={{this.driver}} @column={{hash compact=true}} />`);
        await click(COMPACT);
        assert.dom(COMPACT).includesText('D');
    });

    test('the full identity reads the assigned vehicle from vehicle.display_name or vehicle_name', async function (assert) {
        this.set('driver', { name: 'Full Driver', status: 'active', vehicle: { display_name: 'Relation Truck' } });
        await render(hbs`<Cell::DriverIdentity @row={{this.driver}} @column={{(hash)}} />`);
        assert.deepEqual(badges(), ['Relation Truck']);

        this.set('driver', { name: 'Full Driver', status: 'active', vehicle_name: 'Name Truck' });
        await render(hbs`<Cell::DriverIdentity @row={{this.driver}} @column={{(hash)}} />`);
        assert.deepEqual(badges(), ['Name Truck']);
    });

    test('the full identity honours status badge overrides', async function (assert) {
        this.set('driver', { name: 'Full Driver', status: 'active' });

        await render(hbs`<Cell::DriverIdentity @row={{this.driver}} @column={{hash showStatusBadge=false}} />`);
        assert.dom('[data-test-resource-identity-status-badge]').doesNotExist();

        await render(hbs`<Cell::DriverIdentity @row={{this.driver}} @column={{hash statusBadgeSize="sm" statusBadgeWrapperClass="custom-wrapper"}} />`);
        assert.dom('[data-test-resource-identity-status-badge]').exists();
        assert.dom('.custom-wrapper').exists();
    });

    test('the resource resolves through a function or string path, and the empty text shows otherwise', async function (assert) {
        this.set('row', { assignment: { driver: { name: 'Path Driver' } } });

        await render(hbs`<Cell::DriverIdentity @row={{this.row}} @column={{hash compact=true resourcePath="assignment.driver"}} />`);
        assert.dom(COMPACT).includesText('Path Driver');

        this.set('column', { compact: true, resourcePath: (row) => row.assignment.driver });
        await render(hbs`<Cell::DriverIdentity @row={{this.row}} @column={{this.column}} />`);
        assert.dom(COMPACT).includesText('Path Driver');

        this.set('column', { compact: true, resourcePath: () => null });
        await render(hbs`<Cell::DriverIdentity @row={{this.row}} @column={{this.column}} />`);
        assert.dom('[data-test-identity-empty-text]').hasText('-');

        this.set('column', { compact: true, resourcePath: 'assignment.missing', emptyText: 'Unassigned' });
        await render(hbs`<Cell::DriverIdentity @row={{this.row}} @column={{this.column}} />`);
        assert.dom('[data-test-identity-empty-text]').hasText('Unassigned');
    });

    test('it renders without any column configuration', async function (assert) {
        this.set('driver', { name: 'Bare Driver', status: 'active' });
        await render(hbs`<Cell::DriverIdentity @row={{this.driver}} />`);
        assert.dom(this.element).includesText('Bare Driver');
    });
});
