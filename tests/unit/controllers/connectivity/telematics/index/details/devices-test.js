import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';

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
        const vehicleColumn = controller.columns.find((column) => column.label === 'Vehicle');
        const connectionColumn = controller.columns.find((column) => column.label === 'Connection');
        const attachmentColumn = controller.columns.find((column) => column.label === 'Attachment');

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
        assert.strictEqual(controller.last_online_at, null);
        assert.strictEqual(controller.updated_at, null);
        assert.strictEqual(controller.page, 1);
    });
});
