import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { findAll, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

function badges() {
    return findAll('[data-test-resource-identity-meta-badge]').map((element) => element.textContent.trim());
}

module('Integration | Component | cell/equipment-identity', function (hooks) {
    setupRenderingTest(hooks);

    test('it renders equipped equipment with its type and serial number', async function (assert) {
        this.set('equipment', { name: 'Hoist A', type: 'Hoist', serial_number: 'SN-1', code: 'CODE-1', public_id: 'equipment_1', is_equipped: true, status: 'retired' });

        await render(hbs`<Cell::EquipmentIdentity @row={{this.equipment}} @column={{hash showStatusBadge=true}} />`);

        assert.dom(this.element).includesText('Hoist A');
        assert.deepEqual(badges(), ['Hoist', 'SN-1']);
        assert.dom('[data-test-resource-identity-status-badge]').doesNotExist('the status only tones the dot; it is never printed');
    });

    test('unequipped equipment reports its own status, or unequipped when it has none', async function (assert) {
        this.set('equipment', { name: 'Jack', code: 'CODE-2', public_id: 'equipment_2', status: 'maintenance' });
        await render(hbs`<Cell::EquipmentIdentity @row={{this.equipment}} @column={{(hash)}} />`);
        assert.deepEqual(badges(), ['CODE-2'], 'the code stands in for a missing serial number');

        this.set('equipment', { name: 'Ramp', public_id: 'equipment_3' });
        await render(hbs`<Cell::EquipmentIdentity @row={{this.equipment}} @column={{(hash)}} />`);
        assert.deepEqual(badges(), ['equipment_3'], 'the public id is the last identifier fallback');
    });

    test('it resolves through a resource path and shows the empty text otherwise', async function (assert) {
        this.set('row', { equipment: { name: 'Nested Hoist', type: 'Hoist' } });
        await render(hbs`<Cell::EquipmentIdentity @row={{this.row}} @column={{hash resourcePath="equipment"}} />`);
        assert.dom(this.element).includesText('Nested Hoist');

        this.set('row', { equipment: null });
        await render(hbs`<Cell::EquipmentIdentity @row={{this.row}} @column={{hash resourcePath="equipment" emptyText="None"}} />`);
        assert.dom('[data-test-identity-empty-text]').hasText('None');

        await render(hbs`<Cell::EquipmentIdentity @row={{this.row}} @column={{hash resourcePath="equipment"}} />`);
        assert.dom('[data-test-identity-empty-text]').hasText('-');
    });

    test('it renders without any column configuration', async function (assert) {
        this.set('equipment', { name: 'Bare Hoist' });
        await render(hbs`<Cell::EquipmentIdentity @row={{this.equipment}} />`);
        assert.dom(this.element).includesText('Bare Hoist');
    });
});
