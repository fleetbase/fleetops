import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
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
        assert.dom('[data-test-resource-identity-status-dot]').hasClass('-left-0.5');
        assert.dom('[data-test-resource-identity-status-dot]').hasClass('-top-0.5');
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
});
