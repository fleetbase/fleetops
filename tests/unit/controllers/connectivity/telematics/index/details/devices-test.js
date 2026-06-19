import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import Service from '@ember/service';

class DeviceActionsStub extends Service {
    panel = {
        view() {},
        edit() {},
    };
}

module('Unit | Controller | connectivity/telematics/index/details/devices', function (hooks) {
    setupTest(hooks);

    test('device tab toolbar actions use small Fleetbase controls', function (assert) {
        const controller = this.owner.lookup('controller:connectivity/telematics/index/details/devices');

        assert.ok(controller);
        assert.deepEqual(
            controller.actionButtons.map((button) => button.size),
            ['sm', 'sm'],
            'toolbar action buttons render at sm size'
        );
    });

    test('device filters expose vehicle and connection query contracts', function (assert) {
        const controller = this.owner.lookup('controller:connectivity/telematics/index/details/devices');
        const deviceColumn = controller.columns.find((column) => column.label === 'Telematic Device');
        const vehicleColumn = controller.columns.find((column) => column.label === 'Vehicle');
        const connectionColumn = controller.columns.find((column) => column.label === 'Connection');
        const attachmentColumn = controller.columns.find((column) => column.label === 'Attachment');
        const visibleColumnOrder = controller.columns.filter((column) => !column.hidden).map((column) => column.label);

        assert.strictEqual(deviceColumn.showStatus, false, 'device identity suppresses duplicate connection status in the telematics device tab');
        assert.deepEqual(
            visibleColumnOrder.slice(0, 4),
            ['Telematic Device', 'Connection', 'Provider ID / IMEI', 'Vehicle'],
            'visible columns place connection after device and provider id before vehicle when provider is hidden'
        );
        assert.strictEqual(vehicleColumn.cellComponent, 'cell/vehicle-identity', 'vehicle uses shared vehicle identity cell');
        assert.strictEqual(vehicleColumn.showStatusBadge, true, 'vehicle identity renders attached vehicle status as a compact badge');
        assert.strictEqual(
            vehicleColumn.resourcePath({
                attachable_uuid: 'vehicle_1',
                attached_to_name: 'Truck 1',
            }).vehicle_number,
            'vehicle_1',
            'fallback attached vehicle resource provides badge metadata'
        );
        assert.strictEqual(vehicleColumn.filterComponent, 'filter/model', 'vehicle uses model selector');
        assert.strictEqual(vehicleColumn.filterParam, 'vehicle', 'vehicle filter uses vehicle query param');
        assert.strictEqual(vehicleColumn.model, 'vehicle', 'vehicle filter queries vehicle records');

        assert.strictEqual(connectionColumn.filterParam, 'connection_status', 'connection filter maps to computed connection status');
        assert.deepEqual(
            connectionColumn.filterOptions.map((option) => option.value),
            ['online', 'recently_offline', 'offline', 'long_offline', 'never_connected'],
            'connection filter options are scalar values'
        );
        assert.true(
            connectionColumn.filterOptions.every((option) => typeof option.label === 'string'),
            'connection labels are strings'
        );
        assert.strictEqual(connectionColumn.filterOptionLabel, 'label', 'connection filter reads option labels explicitly');
        assert.strictEqual(connectionColumn.filterOptionValue, 'value', 'connection filter serializes option values explicitly');

        assert.strictEqual(attachmentColumn.filterParam, 'attachment_state', 'attachment state remains available as its own filter');
        assert.true(attachmentColumn.hidden, 'attachment state filter does not duplicate the visible vehicle column');
    });

    test('clearFilters resets every device table filter', function (assert) {
        const controller = this.owner.lookup('controller:connectivity/telematics/index/details/devices');

        controller.query = 'abc';
        controller.status = 'active';
        controller.provider = 'samsara';
        controller.attachment_state = 'attached';
        controller.vehicle = 'vehicle_123';
        controller.connection_status = 'online';
        controller.device_id = 'provider-1';
        controller.type = 'gps_tracker';
        controller.serial_number = 'SN-1';
        controller.last_online_at = '2026-06-17';
        controller.updated_at = '2026-06-17';
        controller.page = 5;

        controller.clearFilters();

        assert.strictEqual(controller.query, null);
        assert.strictEqual(controller.status, null);
        assert.strictEqual(controller.provider, null);
        assert.strictEqual(controller.attachment_state, null);
        assert.strictEqual(controller.vehicle, null);
        assert.strictEqual(controller.connection_status, null);
        assert.strictEqual(controller.device_id, null);
        assert.strictEqual(controller.type, null);
        assert.strictEqual(controller.serial_number, null);
        assert.strictEqual(controller.last_online_at, null);
        assert.strictEqual(controller.updated_at, null);
        assert.strictEqual(controller.page, 1);
    });

    test('device row view actions open overlay panels', function (assert) {
        this.owner.register('service:device-actions', DeviceActionsStub);

        const controller = this.owner.lookup('controller:connectivity/telematics/index/details/devices');
        const nameColumn = controller.columns.find((column) => column.valuePath === 'displayName');
        const actionsColumn = controller.columns.find((column) => column.cellComponent === 'table/cell/dropdown');
        const [viewAction, editAction] = actionsColumn.actions;

        assert.strictEqual(nameColumn.cellComponent, 'cell/device-identity', 'device row uses the shared device identity cell');
        assert.strictEqual(nameColumn.action, controller.deviceActions.panel.view, 'device name opens the device panel');
        assert.strictEqual(viewAction.fn, controller.deviceActions.panel.view, 'dropdown view opens the device panel');
        assert.strictEqual(editAction.fn, controller.deviceActions.panel.edit, 'dropdown edit opens the device panel');
    });

    test('attached vehicle actions only show for vehicle-attached devices', async function (assert) {
        const controller = this.owner.lookup('controller:connectivity/telematics/index/details/devices');
        const actionsColumn = controller.columns.find((column) => column.cellComponent === 'table/cell/dropdown');
        const separator = actionsColumn.actions.find((action) => action.separator && action.isVisible);
        const viewVehicleAction = actionsColumn.actions.find((action) => action.label === 'View attached vehicle');
        const locateVehicleAction = actionsColumn.actions.find((action) => action.label === 'Locate attached vehicle on map');
        const vehicle = { id: 'vehicle_1', displayName: 'Truck 1' };
        const attachedDevice = {
            attachable_uuid: 'vehicle_1',
            attachable_type: 'fleet-ops:vehicle',
            attachable: vehicle,
        };
        const unattachedDevice = {
            attachable_uuid: null,
            attachable_type: null,
            attachable: null,
        };
        const assetAttachedDevice = {
            attachable_uuid: 'asset_1',
            attachable_type: 'fleet-ops:asset',
            attachable: { id: 'asset_1' },
        };

        assert.true(separator.isVisible(attachedDevice), 'separator appears before vehicle actions when a vehicle is attached');
        assert.true(viewVehicleAction.isVisible(attachedDevice), 'view vehicle action appears for attached vehicles');
        assert.true(locateVehicleAction.isVisible(attachedDevice), 'locate vehicle action appears for attached vehicles');
        assert.strictEqual(await controller.resolveAttachedVehicle(attachedDevice), vehicle, 'attached vehicle resolves from the row');

        assert.false(viewVehicleAction.isVisible(unattachedDevice), 'view vehicle action is hidden when no vehicle is attached');
        assert.false(locateVehicleAction.isVisible(assetAttachedDevice), 'locate vehicle action is hidden for non-vehicle attachments');
    });

    test('attached vehicle actions view and locate the vehicle through FleetOps panels', async function (assert) {
        assert.expect(8);

        const vehicle = { id: 'vehicle_1', displayName: 'Truck 1' };
        const device = {
            attachable_uuid: 'vehicle_1',
            attachable_type: 'fleet-ops:vehicle',
            attachable: vehicle,
        };

        class VehicleActionsStub extends Service {
            panel = {
                view(viewedVehicle, options) {
                    assert.strictEqual(viewedVehicle, vehicle, 'vehicle panel opens with the attached vehicle');

                    if (options) {
                        assert.deepEqual(options, { closeOnTransition: true }, 'locate opens the panel with transition-close behavior');
                    }
                },
            };
        }

        class HostRouterStub extends Service {
            transitionTo(route, options) {
                assert.strictEqual(route, 'console.fleet-ops.operations.orders.index', 'locate transitions to live map');
                assert.deepEqual(options, { queryParams: { layout: 'map' } }, 'locate requests map layout');

                return Promise.resolve();
            }
        }

        class MapManagerStub extends Service {
            waitForMap(options) {
                assert.deepEqual(options, { timeoutMs: 8000 }, 'locate waits for the map');

                return Promise.resolve();
            }

            focusResource(focusedVehicle, zoom, options) {
                assert.strictEqual(focusedVehicle, vehicle, 'locate focuses the attached vehicle');
                assert.strictEqual(zoom, 16, 'locate uses the expected zoom');
                options.moveend();
            }
        }

        this.owner.register('service:vehicle-actions', VehicleActionsStub);
        this.owner.register('service:host-router', HostRouterStub);
        this.owner.register('service:map-manager', MapManagerStub);

        const controller = this.owner.lookup('controller:connectivity/telematics/index/details/devices');

        await controller.viewAttachedVehicle(device);
        await controller.locateAttachedVehicle(device);
    });
});
