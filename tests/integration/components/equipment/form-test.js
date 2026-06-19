import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import EquipmentFormComponent from '@fleetbase/fleetops-engine/components/equipment/form';

module('Integration | Component | equipment/form', function (hooks) {
    setupRenderingTest(hooks);

    test('it renders', async function (assert) {
        // Set any properties with this.set('myProperty', 'value');
        // Handle any actions with this.set('myAction', function(val) { ... });

        await render(hbs`<Equipment::Form />`);

        assert.dom().hasText('');

        // Template block usage:
        await render(hbs`
      <Equipment::Form>
        template block text
      </Equipment::Form>
    `);

        assert.dom().hasText('template block text');
    });

    test('it resolves alias and model class equipable types for asset selection', function (assert) {
        const aliasComponent = new EquipmentFormComponent(this.owner, {
            resource: {
                equipable_type: 'fleet-ops:vehicle',
            },
        });

        assert.strictEqual(aliasComponent.equipableModelName, 'vehicle');
        assert.strictEqual(aliasComponent.selectedEquipableType.value, 'fleet-ops:vehicle');

        const classComponent = new EquipmentFormComponent(this.owner, {
            resource: {
                equipable_type: 'Fleetbase\\FleetOps\\Models\\Vehicle',
            },
        });

        assert.strictEqual(classComponent.equipableModelName, 'vehicle');
        assert.strictEqual(classComponent.selectedEquipableType.value, 'fleet-ops:vehicle');

        const driverClassComponent = new EquipmentFormComponent(this.owner, {
            resource: {
                equipable_type: 'Fleetbase\\FleetOps\\Models\\Driver',
            },
        });

        assert.strictEqual(driverClassComponent.equipableModelName, 'driver');
        assert.strictEqual(driverClassComponent.selectedEquipableType.value, 'fleet-ops:driver');
    });
});
