import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';

class HostRouterServiceStub extends Service {
    transitions = [];

    transitionTo(...args) {
        this.transitions.push(args);
    }
}

module('Integration | Component | device-event/details', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:host-router', HostRouterServiceStub);
    });

    test('it resolves through the string-based component resolver', function (assert) {
        assert.ok(this.owner.factoryFor('component:device-event/details'));
    });

    test('it renders event status, device context, timing, and structured diagnostics', async function (assert) {
        this.set('event', {
            id: 'event_uuid_1',
            public_id: 'device_event_1',
            event_type: 'engine_fault',
            severity: 'warning',
            message: 'Engine fault reported',
            processed_at: null,
            occurred_at: '2026-06-22T10:15:00.000Z',
            device_name: 'Gateway 101',
            device_id: 'GW-101',
            device_uuid: 'device_uuid_1',
            device_imei: '867747078951793',
            device_status: 'online',
            telematic_uuid: 'telematic_uuid_1',
            telematic_name: 'AFAQY',
            provider: 'afaqy',
            ident: 'truck-7',
            protocol: 'tcp',
            code: 'P001',
            state: 'active',
            payload: { fault: true },
            data: { voltage: 12.4 },
            meta: { source: 'webhook' },
        });

        await render(hbs`<DeviceEvent::Details @resource={{this.event}} />`);

        assert.dom('h2').hasText('Engine Fault');
        assert.dom().includesText('Warning');
        assert.dom().includesText('Unprocessed');
        assert.dom().includesText('Engine fault reported');
        assert.dom().includesText('Gateway 101');
        assert.dom().includesText('AFAQY');
        assert.dom().includesText('Event Details');
        assert.dom().includesText('Device & Provider');
        assert.dom().includesText('Payload');
        assert.dom('[data-test-device-event-device-link]').hasText('Gateway 101');
        assert.dom('[data-test-device-event-telematic-link]').hasText('AFAQY');
        assert.dom('[data-test-device-event-device-status]').includesText('Online');
        assert.dom().includesText('"fault": true');
        assert.dom().includesText('"voltage": 12.4');
        assert.dom().includesText('"source": "webhook"');

        await click('[data-test-device-event-device-link]');
        await click('[data-test-device-event-telematic-link]');

        assert.deepEqual(this.owner.lookup('service:host-router').transitions, [
            ['console.fleet-ops.connectivity.devices.index.details', 'device_uuid_1'],
            ['console.fleet-ops.connectivity.telematics.details', 'telematic_uuid_1'],
        ]);
    });

    test('it renders processed state from processed_at only', async function (assert) {
        this.set('event', {
            event_type: 'heartbeat',
            severity: 'info',
            message: 'Heartbeat received',
            processed_at: '2026-06-23T12:00:00.000Z',
        });

        await render(hbs`<DeviceEvent::Details @resource={{this.event}} />`);

        assert.dom().includesText('Processed');
    });

    test('it tolerates missing optional associations and structured payloads', async function (assert) {
        this.set('event', {
            event_type: 'heartbeat',
            severity: 'info',
            message: 'Heartbeat received',
        });

        await render(hbs`<DeviceEvent::Details @resource={{this.event}} />`);

        assert.dom('h2').hasText('Heartbeat');
        assert.dom().includesText('Info');
        assert.dom().includesText('Heartbeat received');
        assert.dom('[data-test-device-event-device-link]').doesNotExist();
        assert.dom('[data-test-device-event-telematic-link]').doesNotExist();
        assert.dom().includesText('Payload');
        assert.dom().includesText('Data');
        assert.dom().includesText('Meta');
    });
});
