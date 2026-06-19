import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import Service from '@ember/service';

module('Unit | Controller | connectivity/devices/index/details/vehicle', function (hooks) {
    setupTest(hooks);

    test('it exists', function (assert) {
        let controller = this.owner.lookup('controller:connectivity/devices/index/details/vehicle');
        assert.ok(controller);
    });

    test('vehicle model exposes attached vehicle and latest positions', function (assert) {
        const controller = this.owner.lookup('controller:connectivity/devices/index/details/vehicle');
        const vehicle = { id: 'vehicle_1', displayName: 'Truck 1', location: { coordinates: [106, 47] } };
        const device = { id: 'device_1', attachable_uuid: 'vehicle_1', attachable: vehicle, attached_to_name: 'Truck 1' };
        const positions = [{ id: 'position_1' }];

        controller.model = { device, positions };

        assert.strictEqual(controller.device, device, 'device is read from route model hash');
        assert.strictEqual(controller.vehicle, vehicle, 'attached vehicle is exposed');
        assert.deepEqual(controller.positions, positions, 'latest positions are exposed');
        assert.true(controller.hasPositions, 'positions state is true when positions are loaded');
        assert.true(controller.canOpenVehicle, 'open vehicle action is available');
        assert.true(controller.canLocateVehicle, 'locate action is available when location context exists');
    });

    test('open vehicle actions route to vehicle details and positions', function (assert) {
        assert.expect(4);

        class HostRouterStub extends Service {
            transitionTo(route, model) {
                if (route.endsWith('.positions')) {
                    assert.strictEqual(route, 'console.fleet-ops.management.vehicles.index.details.positions', 'opens vehicle positions tab');
                } else {
                    assert.strictEqual(route, 'console.fleet-ops.management.vehicles.index.details', 'opens vehicle detail route');
                }

                assert.strictEqual(model.id, 'vehicle_1', 'vehicle model is passed through');
            }
        }

        this.owner.register('service:host-router', HostRouterStub);

        const controller = this.owner.lookup('controller:connectivity/devices/index/details/vehicle');
        controller.model = {
            device: {
                attachable: { id: 'vehicle_1', displayName: 'Truck 1' },
            },
            positions: [],
        };

        controller.openVehicle();
        controller.openVehiclePositions();
    });

    test('locate vehicle transitions to live map and focuses attached vehicle', async function (assert) {
        assert.expect(6);

        const vehicle = { id: 'vehicle_1', displayName: 'Truck 1', location: { coordinates: [106, 47] } };

        class HostRouterStub extends Service {
            transitionTo(route, options) {
                assert.strictEqual(route, 'console.fleet-ops.operations.orders.index', 'locate transitions to live map');
                assert.deepEqual(options, { queryParams: { layout: 'map' } }, 'locate requests map layout');

                return Promise.resolve();
            }
        }

        class MapManagerStub extends Service {
            waitForMap(options) {
                assert.deepEqual(options, { timeoutMs: 8000 }, 'locate waits for map readiness');

                return Promise.resolve();
            }

            focusResource(focusedVehicle, zoom, options) {
                assert.strictEqual(focusedVehicle, vehicle, 'attached vehicle is focused');
                assert.strictEqual(zoom, 16, 'expected zoom is used');
                options.moveend();
            }
        }

        class VehicleActionsStub extends Service {
            panel = {
                view(focusedVehicle, options) {
                    assert.deepEqual({ id: focusedVehicle.id, options }, { id: 'vehicle_1', options: { closeOnTransition: true } }, 'vehicle panel opens after focus');
                },
            };
        }

        this.owner.register('service:host-router', HostRouterStub);
        this.owner.register('service:map-manager', MapManagerStub);
        this.owner.register('service:vehicle-actions', VehicleActionsStub);

        const controller = this.owner.lookup('controller:connectivity/devices/index/details/vehicle');
        controller.model = {
            device: {
                attachable: vehicle,
            },
            positions: [],
        };

        await controller.locateVehicle();
    });
});
