import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | sensor/panel-header', function (hooks) {
    setupRenderingTest(hooks);

    test('it renders compact operational sensor identity', async function (assert) {
        this.set('resource', {
            name: 'Fuel Level Sensor',
            photo_url: 'https://example.test/sensor.png',
            status: 'active',
            threshold_status: 'normal',
            type: 'fuel_level',
            serial_number: 'SNS-22',
            last_value: 72,
            unit: '%',
            device: {
                displayName: 'BX-046',
            },
            last_reading_at: '2026-06-18T15:28:00Z',
        });

        await render(hbs`<Sensor::PanelHeader @resource={{this.resource}} />`);

        assert.dom().includesText('Fuel Level Sensor');
        assert.dom().includesText('Active');
        assert.dom().includesText('Normal');
        assert.dom().includesText('SNS-22');
        assert.dom().includesText('72 %');
        assert.dom().includesText('BX-046');
        assert.dom('img').hasClass('rounded-md');
    });

    test('it falls back when sensor values are missing', async function (assert) {
        this.set('resource', {
            imei: 'IMEI-88',
            status: 'inactive',
        });

        await render(hbs`<Sensor::PanelHeader @resource={{this.resource}} />`);

        assert.dom().includesText('IMEI-88');
        assert.dom().includesText('Inactive');
        assert.dom().includesText('No reading yet');
    });
});
