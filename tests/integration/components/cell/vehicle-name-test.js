import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | cell/vehicle-name', function (hooks) {
    setupRenderingTest(hooks);

    test('it renders the vehicle name with its image and online indicator', async function (assert) {
        this.set('vehicle', { id: 'vehicle_1', photo_url: '/van.png', online: true });

        await render(hbs`<Cell::VehicleName @row={{this.vehicle}} @value="Van 1" @column={{hash showOnlineIndicator=true hasOnline=true mediaPath="photo_url"}} />`);

        assert.dom('a').hasText('Van 1');
        assert.dom('img').hasAttribute('data-vehicle', 'vehicle_1').hasClass('mx-2');
        assert.dom('svg[data-icon="circle"]').hasClass('text-green-500');
    });

    test('it yields block content and hides the indicator by default', async function (assert) {
        this.set('vehicle', { id: 'vehicle_2', online: false });

        await render(hbs`<Cell::VehicleName @row={{this.vehicle}} @value="Van 2" @column={{(hash)}}>custom label</Cell::VehicleName>`);

        assert.dom('a').hasText('custom label');
        assert.dom('img').hasClass('mr-2');
        assert.dom('svg[data-icon="circle"]').doesNotExist();
    });
});
