import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | device/pill', function (hooks) {
    setupRenderingTest(hooks);

    test('it renders the device name, identifier and online state from @device or @resource', async function (assert) {
        this.set('device', { name: 'Tracker 1', device_id: 'DEV-1', serial_number: 'SN-1', online: true });

        await render(hbs`<Device::Pill @device={{this.device}} />`);
        assert.dom('.fleetbase-pill').includesText('Tracker 1').includesText('DEV-1');
        assert.dom('svg[data-icon="circle"]').hasClass('text-green-500');

        this.set('device', { name: 'Tracker 2', serial_number: 'SN-2', online: false });
        await render(hbs`<Device::Pill @resource={{this.device}} />`);
        assert.dom('.fleetbase-pill').includesText('Tracker 2').includesText('SN-2', 'the serial number stands in for a missing device id');
        assert.dom('svg[data-icon="circle"]').hasClass('text-yellow-200');
    });

    test('the identifier falls back through imei and public id, and clicks hand back the device', async function (assert) {
        const clicked = [];
        this.set('device', { name: 'Tracker 3', imei: 'IMEI-3' });
        this.set('onClick', (resource) => clicked.push(resource));

        await render(hbs`<Device::Pill @device={{this.device}} @onClick={{this.onClick}} />`);
        assert.dom('.fleetbase-pill').includesText('IMEI-3');
        await click('.fleetbase-pill a');
        assert.strictEqual(clicked[0], this.device);

        this.set('device', { name: 'Tracker 4', public_id: 'device_4' });
        await render(hbs`<Device::Pill @device={{this.device}} />`);
        assert.dom('.fleetbase-pill').includesText('device_4');
    });
});
