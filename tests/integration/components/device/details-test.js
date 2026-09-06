import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import registerTemplateOnly from 'dummy/tests/helpers/register-template-only';

module('Integration | Component | device/details', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        registerTemplateOnly(this.owner, 'custom-field/yield', hbs`<div data-test-custom-fields></div>`);
    });

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

    test('it renders the last seen timestamp in the shared telematics format', async function (assert) {
        this.set('device', {
            displayName: 'Gateway 101',
            connection_status: 'online',
            device_id: 'VG-101',
            last_online_at: new Date(2026, 5, 18, 15, 28),
        });

        await render(hbs`<Device::Details @resource={{this.device}} />`);

        assert.dom().includesText('Last seen: 18 Jun 2026, 15:28');
    });

    test('it renders a placeholder when the device has never reported', async function (assert) {
        this.set('device', {
            displayName: 'Gateway 101',
            connection_status: 'never_connected',
            device_id: 'VG-101',
        });

        await render(hbs`<Device::Details @resource={{this.device}} />`);

        assert.dom().includesText('Last seen: -');
    });
});
