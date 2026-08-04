import { module, test } from 'qunit';
import DevicePanelHeaderComponent from 'dummy/components/device/panel-header';

function makePanelHeader(resource) {
    const component = Object.create(DevicePanelHeaderComponent.prototype);
    component.args = { resource };

    return component;
}

module('Unit | Component | device/panel-header', function () {
    test('connection status prefers the telematics connection state', function (assert) {
        const component = makePanelHeader({
            connection_status: 'recently_offline',
            status: 'active',
            is_online: true,
        });

        assert.strictEqual(component.connectionStatus, 'recently_offline');
    });

    test('connection status falls back to the record status then to the online flag', function (assert) {
        assert.strictEqual(makePanelHeader({ status: 'active', is_online: false }).connectionStatus, 'active');
        assert.strictEqual(makePanelHeader({ is_online: true }).connectionStatus, 'online');
        assert.strictEqual(makePanelHeader({ online: true }).connectionStatus, 'online');
        assert.strictEqual(makePanelHeader({ is_online: false }).connectionStatus, 'offline');
        assert.strictEqual(makePanelHeader(undefined).connectionStatus, 'offline');
    });

    test('last online timestamp is read from either payload casing', function (assert) {
        assert.strictEqual(makePanelHeader({ last_online_at: '2026-06-18T15:28:00Z' }).lastOnlineAt, '2026-06-18T15:28:00Z');
        assert.strictEqual(makePanelHeader({ lastOnlineAt: '2026-06-18T15:28:00Z' }).lastOnlineAt, '2026-06-18T15:28:00Z');
        assert.strictEqual(makePanelHeader({}).lastOnlineAt, undefined);
    });
});
