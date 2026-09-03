import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | vehicle/pill', function (hooks) {
    setupRenderingTest(hooks);

    test('it renders the vehicle name and plate with an online indicator, and clicks hand back the vehicle', async function (assert) {
        const clicked = [];
        this.set('vehicle', { name: 'Van 12', plate_number: 'SGX 1234', online: true });
        this.set('onClick', (resource) => clicked.push(resource));

        await render(hbs`<Vehicle::Pill @vehicle={{this.vehicle}} @onClick={{this.onClick}} />`);

        assert.dom('.fleetbase-pill').includesText('Van 12').includesText('SGX 1234');
        assert.dom('svg[data-icon="circle"]').hasClass('text-green-500');
        await click('.fleetbase-pill a');
        assert.strictEqual(clicked[0], this.vehicle, 'the pill receives the vehicle as its resource');
    });

    test('the title and identifier fall back through the vehicle fields', async function (assert) {
        this.set('vehicle', { yearMakeModel: '2020 Ford Transit', vin: 'VIN-1', online: false });
        await render(hbs`<Vehicle::Pill @resource={{this.vehicle}} />`);
        assert.dom('.fleetbase-pill').includesText('2020 Ford Transit').includesText('VIN-1');
        assert.dom('svg[data-icon="circle"]').hasClass('text-yellow-200');

        this.set('vehicle', { serial_number: 'SER-1' });
        await render(hbs`<Vehicle::Pill @resource={{this.vehicle}} @titleFallback="Unnamed" />`);
        assert.dom('.fleetbase-pill').includesText('Unnamed').includesText('SER-1');

        this.set('vehicle', { call_sign: 'CS-1' });
        await render(hbs`<Vehicle::Pill @resource={{this.vehicle}} />`);
        assert.dom('.fleetbase-pill').includesText('No vehicle').includesText('CS-1');
    });

    test('without a vehicle it shows the fallbacks and no indicator', async function (assert) {
        await render(hbs`<Vehicle::Pill />`);
        assert.dom('.fleetbase-pill').includesText('No vehicle').includesText('-');
        assert.dom('svg[data-icon="circle"]').doesNotExist();
    });
});
