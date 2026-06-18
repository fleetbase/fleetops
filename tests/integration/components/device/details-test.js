import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | device/details', function (hooks) {
    setupRenderingTest(hooks);

    test('it renders the operational overview without optional associations', async function (assert) {
        this.set('device', {
            displayName: 'Gateway 101',
            connection_status: 'never_connected',
            provider: 'samsara',
            device_id: 'VG-101',
            status: 'active',
        });

        await render(hbs`<Device::Details @resource={{this.device}} />`);

        assert.dom('h2').hasText('Gateway 101');
        assert.dom().includesText('Operational Snapshot');
        assert.dom().includesText('Critical Details');
        assert.dom().includesText('Unattached');
        assert.dom().includesText('Never Connected');
    });

    test('it renders provider and vehicle context when present', async function (assert) {
        this.set('device', {
            displayName: 'Cold Chain Tracker',
            connection_status: 'online',
            attached_to_name: 'Truck 24',
            device_id: 'CC-24',
            status: 'active',
            telematic: {
                provider_descriptor: {
                    label: 'Geotab',
                    icon: '/assets/images/telematics/providers/geotab.webp',
                },
            },
        });

        await render(hbs`<Device::Details @resource={{this.device}} />`);

        assert.dom().includesText('Cold Chain Tracker');
        assert.dom().includesText('Truck 24');
        assert.dom().includesText('Geotab');
        assert.dom().includesText('Online');
    });
});
