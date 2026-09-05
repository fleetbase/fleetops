import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | cell/attached-vehicle', function (hooks) {
    setupRenderingTest(hooks);

    test('it delegates attached vehicles to the shared vehicle identity cell', async function (assert) {
        this.set('device', {
            attachable_uuid: 'vehicle-1',
            attachable_type: 'fleet-ops:vehicle',
            attached_to_name: 'Truck 100',
            attachable: {
                id: 'vehicle-1',
                displayName: 'Truck 100',
                plate_number: 'TRK-100',
                online: true,
            },
        });
        this.set('column', {});

        await render(hbs`<Cell::AttachedVehicle @row={{this.device}} @column={{this.column}} />`);

        assert.dom('[data-test-resource-identity-image]').exists();
        assert.dom('[data-test-resource-identity-status-dot]').exists();
        assert.dom('[data-test-resource-identity-status-dot]').hasClass('left-0');
        assert.dom('[data-test-resource-identity-status-dot]').hasClass('top-0');
        assert.dom(this.element).includesText('Truck 100');
        assert.dom(this.element).includesText('TRK-100');
    });

    test('it preserves the unattached fallback state', async function (assert) {
        this.set('device', {
            attachable_uuid: null,
            attachable_type: 'fleet-ops:vehicle',
            attached_to_name: null,
            attachable: null,
        });
        this.set('column', {});

        await render(hbs`<Cell::AttachedVehicle @row={{this.device}} @column={{this.column}} />`);

        assert.dom(this.element).includesText('Unattached');
        assert.dom('[data-test-resource-identity-image]').doesNotExist();
    });

    test('it uses the attachable relation when present and treats non-vehicle attachments as unattached', async function (assert) {
        this.set('device', { attachable_uuid: 'vehicle_9', attachable_type: 'App\\Models\\Vehicle', attachable: { name: 'Related Van', status: 'active' } });
        this.set('column', {});

        await render(hbs`<Cell::AttachedVehicle @row={{this.device}} @column={{this.column}} />`);
        assert.dom(this.element).includesText('Related Van');

        this.set('device', { attachable_uuid: 'driver_9', attachable_type: 'App\\Models\\Driver', attached_to_name: 'A Driver' });
        await render(hbs`<Cell::AttachedVehicle @row={{this.device}} @column={{this.column}} />`);
        assert.dom(this.element).includesText('Unattached');
    });

    test('clicking the vehicle delegates the device to the cell handler and the column action', async function (assert) {
        const calls = [];
        this.set('device', { attachable_uuid: 'vehicle_9', attached_to_name: 'Clickable Van' });
        this.set('column', { action: (device) => calls.push(['action', device]) });
        this.set('onClick', (device) => calls.push(['onClick', device]));

        await render(hbs`<Cell::AttachedVehicle @row={{this.device}} @column={{this.column}} @onClick={{this.onClick}} />`);
        await click('button');

        assert.deepEqual(
            calls.map(([name, device]) => [name, device === this.device]),
            [
                ['onClick', true],
                ['action', true],
            ]
        );

        this.set('column', {});
        await render(hbs`<Cell::AttachedVehicle @row={{this.device}} @column={{this.column}} />`);
        await click('button');
        assert.strictEqual(calls.length, 2, 'no handlers, no calls');
    });

    test('it renders without any column configuration', async function (assert) {
        this.set('device', { attachable_uuid: 'vehicle_1', attached_to_name: 'Bare Van' });
        await render(hbs`<Cell::AttachedVehicle @row={{this.device}} />`);
        assert.dom(this.element).includesText('Bare Van');
    });
});
