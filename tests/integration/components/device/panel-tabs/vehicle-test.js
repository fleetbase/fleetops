import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, find, findAll, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';

function button(text) {
    return findAll('button').find((element) => element.textContent.trim() === text);
}

module('Integration | Component | device/panel-tabs/vehicle', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        const calls = (this.calls = []);
        const test = this;

        this.transitionFails = false;
        this.owner.register(
            'service:device-actions',
            class extends Service {
                attachToVehicle(device, options) {
                    calls.push(['attachToVehicle', device?.id]);
                    options.callback();
                }

                detachFromVehicle(device, options) {
                    calls.push(['detachFromVehicle', device?.id]);
                    options.callback();
                }
            }
        );
        this.owner.register(
            'service:vehicle-actions',
            class extends Service {
                panel = {
                    view: (vehicle, options) => calls.push(['panel.view', vehicle.id, options?.closeOnTransition]),
                };
            }
        );
        this.owner.register(
            'service:host-router',
            class extends Service {
                async transitionTo(route, options) {
                    calls.push(['transitionTo', route, options?.queryParams?.layout]);
                    if (test.transitionFails) {
                        throw new Error('a transition is already active');
                    }
                }
            }
        );
        this.owner.register(
            'service:map-manager',
            class extends Service {
                async waitForMap(options) {
                    calls.push(['waitForMap', options.timeoutMs]);
                }

                focusResource(resource, zoom, options) {
                    calls.push(['focusResource', resource.id, zoom]);
                    options.moveend();
                }
            }
        );
    });

    test('an attached vehicle renders its identity, driver and device context', async function (assert) {
        this.set('device', {
            id: 'device_1',
            displayName: 'Gateway 101',
            last_online_at: new Date(2026, 5, 18, 15, 28),
            attachable: {
                id: 'vehicle_1',
                displayName: 'Truck 24',
                plate_number: 'ABC-123',
                photo_url: 'https://cdn.example.com/truck.png',
                status: 'in_use',
                location: { type: 'Point', coordinates: [-97.74, 30.27] },
                driver: { displayName: 'Sam Driver' },
            },
        });

        await render(hbs`<Device::PanelTabs::Vehicle @resource={{this.device}} />`);

        assert.dom('h2').hasText('Vehicle Attachment');
        assert.dom('h3').hasText('Truck 24');
        assert.dom('.status-badge').includesText('In Use');
        assert.dom().includesText('ABC-123');
        assert.dom().includesText('Sam Driver');
        assert.dom().includesText('Gateway 101');
        assert.dom().includesText('18 Jun 2026, 15:28');
        assert.dom('img').hasAttribute('src', 'https://cdn.example.com/truck.png');
        assert.ok(button('Open Vehicle'), 'a vehicle with an id can be opened');
        assert.ok(button('Locate'), 'a vehicle with a location can be located');
        assert.ok(button('Change Vehicle'));
        assert.ok(button('Detach'));
        assert.notOk(button('Attach Vehicle'));
    });

    test('the attachment actions reach the device service and reload the device', async function (assert) {
        const reloads = [];
        this.set('device', { id: 'device_1', attachable: { id: 'vehicle_1', name: 'Truck 24' }, reload: () => reloads.push('reload') });

        await render(hbs`<Device::PanelTabs::Vehicle @resource={{this.device}} />`);

        await click(button('Change Vehicle'));
        await click(button('Detach'));
        assert.deepEqual(this.calls, [
            ['attachToVehicle', 'device_1'],
            ['detachFromVehicle', 'device_1'],
        ]);
        assert.deepEqual(reloads, ['reload', 'reload']);
    });

    test('opening a vehicle prefers the panel over a route transition', async function (assert) {
        this.set('device', { id: 'device_1', attachable: { id: 'vehicle_1', name: 'Truck 24' } });

        await render(hbs`<Device::PanelTabs::Vehicle @resource={{this.device}} />`);

        await click(button('Open Vehicle'));
        assert.deepEqual(this.calls, [['panel.view', 'vehicle_1', undefined]]);
    });

    test('without a vehicle panel, opening falls back to the vehicle details route', async function (assert) {
        const calls = this.calls;
        this.owner.register('service:vehicle-actions', class extends Service {}, { instantiate: true });
        this.owner.register(
            'service:host-router',
            class extends Service {
                async transitionTo(route, model) {
                    calls.push(['transitionTo', route, model?.id]);
                }
            }
        );
        this.set('device', { id: 'device_1', attachable: { id: 'vehicle_1', name: 'Truck 24' } });

        await render(hbs`<Device::PanelTabs::Vehicle @resource={{this.device}} />`);

        await click(button('Open Vehicle'));
        assert.deepEqual(this.calls, [['transitionTo', 'console.fleet-ops.management.vehicles.index.details', 'vehicle_1']]);
    });

    test('locating a vehicle opens the live map, waits for it and focuses the vehicle', async function (assert) {
        this.set('device', { id: 'device_1', attachable: { id: 'vehicle_1', name: 'Truck 24', last_position: { type: 'Point', coordinates: [0, 0] } } });

        await render(hbs`<Device::PanelTabs::Vehicle @resource={{this.device}} />`);

        await click(button('Locate'));
        assert.deepEqual(this.calls, [
            ['transitionTo', 'console.fleet-ops.operations.orders.index', 'map'],
            ['waitForMap', 8000],
            ['focusResource', 'vehicle_1', 16],
            ['panel.view', 'vehicle_1', true],
        ]);
    });

    test('a failed transition still locates the vehicle', async function (assert) {
        this.transitionFails = true;
        this.set('device', { id: 'device_1', attachable: { id: 'vehicle_1', name: 'Truck 24', location: {} } });

        await render(hbs`<Device::PanelTabs::Vehicle @resource={{this.device}} />`);

        await click(button('Locate'));
        assert.deepEqual(this.calls.slice(1), [
            ['waitForMap', 8000],
            ['focusResource', 'vehicle_1', 16],
            ['panel.view', 'vehicle_1', true],
        ]);
    });

    test('a device with no vehicle offers only the attach action', async function (assert) {
        this.set('device', { id: 'device_1', name: 'Gateway 101' });

        await render(hbs`<Device::PanelTabs::Vehicle @model={{this.device}} />`);

        assert.dom().includesText('No vehicle attached');
        assert.dom().includesText('Attach this device to a vehicle so telemetry has fleet context.');
        assert.notOk(button('Open Vehicle'));
        assert.notOk(button('Locate'));
        assert.notOk(button('Detach'));

        await click(button('Attach Vehicle'));
        assert.deepEqual(this.calls, [['attachToVehicle', 'device_1']], 'a device without a reload method is still handled');
    });

    test('a vehicle known only by uuid renders the card without the open and locate actions', async function (assert) {
        this.set('device', { id: 'device_1', attached_to_name: 'Truck 24', attachable_uuid: 'vehicle_uuid_1', attachable: { online: true } });

        await render(hbs`<Device::PanelTabs::Vehicle @resource={{this.device}} />`);

        assert.dom('h3').hasText('Truck 24');
        assert.dom('.status-badge').includesText('Online', 'an online vehicle without a status falls back to online');
        assert.dom().includesText('vehicle_uuid_1', 'the subtitle falls back to the attachable uuid');
        assert.notOk(button('Open Vehicle'));
        assert.notOk(button('Locate'));
        assert.ok(button('Detach'));
        assert.ok(find('img').getAttribute('src').startsWith('data:image/svg+xml'), 'the vehicle avatar falls back');
    });

    test('an offline vehicle with no status renders no badge and dashes for the unknown fields', async function (assert) {
        this.set('device', { id: 'device_1', attachable: { id: 'vehicle_1', name: 'Truck 24', online: false } });

        await render(hbs`<Device::PanelTabs::Vehicle @resource={{this.device}} />`);

        assert.dom('h3').hasText('Truck 24');
        assert.dom('.status-badge').doesNotExist();
        assert.dom().includesText('-', 'the subtitle, driver and last-seen fields fall back to a dash');
    });
});
