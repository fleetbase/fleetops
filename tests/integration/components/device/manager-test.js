import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, findAll, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';

function fakeModal(options = {}) {
    const modal = { events: [], options };
    modal.getOption = (key) => options[key];
    modal.startLoading = () => modal.events.push('startLoading');
    modal.stopLoading = () => modal.events.push('stopLoading');
    modal.done = () => modal.events.push('done');
    return modal;
}

function detachButton(index = 0) {
    return findAll('button').filter((button) => /Detach/.test(button.textContent))[index];
}

module('Integration | Component | device/manager', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        const calls = (this.calls = []);
        const test = this;
        this.devices = [];
        this.queryFails = false;
        this.postFails = false;
        this.modals = [];
        const modals = this.modals;
        this.owner.register(
            'service:modals-manager',
            class extends Service {
                show(name, options) {
                    modals.push({ name, options });
                }

                confirm(options) {
                    modals.push({ name: 'confirm', options });
                }
            }
        );
        this.owner.register(
            'service:notifications',
            class extends Service {
                success(message) {
                    calls.push(['success', message]);
                }

                serverError(error) {
                    calls.push(['serverError', error.message]);
                }
            }
        );
        this.owner.register(
            'service:fetch',
            class extends Service {
                async post(url, body) {
                    calls.push(['post', url, body]);
                    if (test.postFails) {
                        throw new Error('attach failed');
                    }
                    return body;
                }
            }
        );
        const store = this.owner.lookup('service:store');
        store.query = async (type, params) => {
            calls.push(['query', type, params]);
            if (test.queryFails) {
                throw new Error('devices down');
            }
            return test.devices;
        };
        this.makeVehicle = (attributes = {}) => store.createRecord('vehicle', { id: 'vehicle_1', ...attributes });
    });

    test('it lists the attached devices and attaches a new one through the modal', async function (assert) {
        this.devices = [{ id: 'dev_1', name: 'Tracker', device_id: 'IMEI-1' }];
        this.set('resource', this.makeVehicle({ name: 'Truck 1' }));

        await render(hbs`<Device::Manager @resource={{this.resource}} />`);

        assert.deepEqual(this.calls, [['query', 'device', { attachable_uuid: 'vehicle_1' }]]);
        assert.dom().includesText('IMEI-1');
        assert.dom().doesNotIncludeText('No Devices Attached');
        assert.dom('button').isNotDisabled();

        await click('button');
        assert.strictEqual(this.modals.length, 1);
        const { name, options } = this.modals[0];
        assert.strictEqual(name, 'modals/attach-device');
        assert.strictEqual(options.title, 'Select device to attach to Truck 1');
        assert.strictEqual(options.acceptButtonText, 'Confirm & Attach Device');
        assert.strictEqual(options.selectedDevice, null);

        const noSelection = fakeModal({ selectedDevice: null });
        await options.confirm(noSelection);
        assert.deepEqual(noSelection.events, []);
        assert.strictEqual(this.calls.length, 1, 'nothing is posted without a selection');

        const modal = fakeModal({ selectedDevice: { id: 'dev_9' } });
        await options.confirm(modal);
        assert.deepEqual(this.calls.slice(1), [
            ['post', 'vehicles/vehicle_1/attach-device', { device: 'dev_9' }],
            ['query', 'device', { attachable_uuid: 'vehicle_1' }],
            ['success', 'Device attached successfully.'],
        ]);
        assert.deepEqual(modal.events, ['startLoading', 'done']);

        this.postFails = true;
        const failing = fakeModal({ selectedDevice: { id: 'dev_9' } });
        await options.confirm(failing);
        assert.deepEqual(this.calls.at(-1), ['serverError', 'attach failed']);
        assert.deepEqual(failing.events, ['startLoading', 'stopLoading']);
    });

    test('detaching confirms with the device and resource names, then reloads', async function (assert) {
        this.devices = [
            { id: 'dev_1', displayName: 'Cab Cam', name: 'Camera', device_id: 'IMEI-1' },
            { id: 'dev_2', name: 'Tracker', device_id: 'IMEI-2' },
            { id: 'dev_3', device_id: 'IMEI-3' },
            { id: 'dev_4' },
        ];
        this.set('resource', this.makeVehicle({ plate_number: 'ABC-123' }));

        await render(hbs`<Device::Manager @resource={{this.resource}} @namePath="plate_number" />`);

        assert.strictEqual(findAll('button').filter((button) => /Detach/.test(button.textContent)).length, 4);

        await click(detachButton(0));
        assert.strictEqual(this.modals.at(-1).options.title, 'Detach Cab Cam from ABC-123?');
        assert.strictEqual(this.modals.at(-1).options.body, 'This detaches Cab Cam from ABC-123 and stops telemetry updates and events for this vehicle.');
        await click(detachButton(1));
        assert.strictEqual(this.modals.at(-1).options.title, 'Detach Tracker from ABC-123?');
        await click(detachButton(2));
        assert.strictEqual(this.modals.at(-1).options.title, 'Detach IMEI-3 from ABC-123?');
        await click(detachButton(3));
        assert.strictEqual(this.modals.at(-1).options.title, 'Detach Device from ABC-123?');

        const modal = fakeModal();
        await this.modals.at(-1).options.confirm(modal);
        assert.deepEqual(this.calls.slice(1), [
            ['post', 'vehicles/vehicle_1/detach-device', { device: 'dev_4' }],
            ['query', 'device', { attachable_uuid: 'vehicle_1' }],
            ['success', 'Detached Device from ABC-123.'],
        ]);
        assert.deepEqual(modal.events, ['startLoading', 'done']);

        this.postFails = true;
        const failing = fakeModal();
        await this.modals.at(-1).options.confirm(failing);
        assert.deepEqual(this.calls.at(-1), ['serverError', 'attach failed']);
        assert.deepEqual(failing.events, ['startLoading', 'stopLoading']);
    });

    test('the resource name falls back through display name, tracking, public id and model name', async function (assert) {
        this.set('resource', this.makeVehicle());
        await render(hbs`<Device::Manager @resource={{this.resource}} />`);
        await click('button');
        assert.strictEqual(this.modals.at(-1).options.title, 'Select device to attach to vehicle');

        this.set('resource', this.makeVehicle({ id: 'vehicle_2', public_id: 'vehicle_abc' }));
        await render(hbs`<Device::Manager @resource={{this.resource}} />`);
        await click('button');
        assert.strictEqual(this.modals.at(-1).options.title, 'Select device to attach to vehicle_abc');

        this.set('resource', this.makeVehicle({ id: 'vehicle_3', display_name: 'Van 3' }));
        await render(hbs`<Device::Manager @resource={{this.resource}} />`);
        await click('button');
        assert.strictEqual(this.modals.at(-1).options.title, 'Select device to attach to Van 3');
    });

    test('a failing device load leaves the list empty', async function (assert) {
        this.queryFails = true;
        this.set('resource', this.makeVehicle({ name: 'Truck 1' }));

        await render(hbs`<Device::Manager @resource={{this.resource}} />`);

        assert.dom().includesText('No Devices Attached');
        assert.dom().includesText('for this vehicle.');
        assert.deepEqual(this.calls, [['query', 'device', { attachable_uuid: 'vehicle_1' }]]);
    });
});
