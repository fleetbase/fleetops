import { module, test } from 'qunit';
import DeviceDetailsComponent from 'dummy/components/device/details';

function makeDetails(resource) {
    const component = Object.create(DeviceDetailsComponent.prototype);
    component.args = { resource };

    return component;
}

module('Unit | Component | device/details', function () {
    test('last seen label is read from either payload casing', function (assert) {
        assert.strictEqual(makeDetails({ last_online_at: '2026-06-18T15:28:00Z' }).lastSeenLabel, '2026-06-18T15:28:00Z');
        assert.strictEqual(makeDetails({ lastOnlineAt: '2026-06-18T15:28:00Z' }).lastSeenLabel, '2026-06-18T15:28:00Z');
        assert.strictEqual(makeDetails({}).lastSeenLabel, undefined);
    });

    test('connection status prefers the telematics connection state over the online flag', function (assert) {
        assert.strictEqual(makeDetails({ connection_status: 'long_offline', is_online: true }).connectionStatus, 'long_offline');
        assert.strictEqual(makeDetails({ is_online: true }).connectionStatus, 'online');
        assert.strictEqual(makeDetails({ is_online: false }).connectionStatus, 'offline');
    });
});
