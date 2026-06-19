import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | device/panel-header', function (hooks) {
    setupRenderingTest(hooks);

    test('it renders compact operational device identity', async function (assert) {
        this.set('resource', {
            displayName: 'BX-046',
            photo_url: 'https://example.test/device.png',
            connection_status: 'offline',
            provider: 'afaqy',
            type: 'gps',
            imei: '864022084024776',
            attached_to_name: 'CLD-06',
            last_online_at: '2026-06-18T15:28:00Z',
        });

        await render(hbs`<Device::PanelHeader @resource={{this.resource}} />`);

        assert.dom().includesText('BX-046');
        assert.dom().includesText('Offline');
        assert.dom().includesText('Afaqy');
        assert.dom().includesText('864022084024776');
        assert.dom().includesText('CLD-06');
        assert.dom('img').hasClass('rounded-md');
        assert.dom('img').hasClass('shadow-sm');
    });

    test('it falls back when device values are missing', async function (assert) {
        this.set('resource', {
            serial_number: 'SN-100',
            is_online: true,
        });

        await render(hbs`<Device::PanelHeader @resource={{this.resource}} />`);

        assert.dom().includesText('SN-100');
        assert.dom().includesText('Online');
        assert.dom().includesText('No last online');
    });

    test('it renders safe fallbacks without a resource', async function (assert) {
        await render(hbs`<Device::PanelHeader />`);

        assert.dom().includesText('-');
        assert.dom().includesText('Offline');
        assert.dom().includesText('No last online');
    });
});
