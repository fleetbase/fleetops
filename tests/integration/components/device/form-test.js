import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import DeviceFormComponent from '@fleetbase/fleetops-engine/components/device/form';

module('Integration | Component | device/form', function (hooks) {
    setupRenderingTest(hooks);

    test('it renders', async function (assert) {
        // Set any properties with this.set('myProperty', 'value');
        // Handle any actions with this.set('myAction', function(val) { ... });

        await render(hbs`<Device::Form />`);

        assert.dom().hasText('');

        // Template block usage:
        await render(hbs`
      <Device::Form>
        template block text
      </Device::Form>
    `);

        assert.dom().hasText('template block text');
    });

    test('it only locks telematic selection for persisted provider-synced devices', function (assert) {
        const newDevice = new DeviceFormComponent(this.owner, {
            resource: {
                isNew: true,
                telematic_uuid: null,
            },
        });

        const persistedManualDevice = new DeviceFormComponent(this.owner, {
            resource: {
                isNew: false,
                telematic_uuid: null,
            },
        });

        const persistedSyncedDevice = new DeviceFormComponent(this.owner, {
            resource: {
                isNew: false,
                telematic_uuid: 'telematic_1',
            },
        });

        const persistedSyncedRelationshipDevice = new DeviceFormComponent(this.owner, {
            resource: {
                isNew: false,
                telematic: { id: 'telematic_2' },
            },
        });

        assert.false(newDevice.isTelematicLocked, 'new devices remain selectable');
        assert.false(persistedManualDevice.isTelematicLocked, 'persisted manual devices remain selectable');
        assert.true(persistedSyncedDevice.isTelematicLocked, 'persisted devices with telematic_uuid are locked');
        assert.true(persistedSyncedRelationshipDevice.isTelematicLocked, 'persisted devices with telematic relationship are locked');
    });
});
