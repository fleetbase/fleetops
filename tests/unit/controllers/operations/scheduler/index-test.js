import Service from '@ember/service';
import { module, test } from 'qunit';
import { settled } from '@ember/test-helpers';
import { setupTest } from 'dummy/tests/helpers';

class SocketStub extends Service {
    listened = [];
    callbacks = {};
    channels = [];
    closed = [];
    // Channels named here are only "subscribed" when subscribeNow() runs, like the real
    // service, which subscribes a moment after listen() returns.
    deferred = new Set();

    listen(name, callback) {
        this.listened.push(name);
        this.callbacks[name] = callback;
        if (!this.deferred.has(name)) {
            this.subscribeNow(name);
        }

        return Promise.resolve();
    }

    subscribeNow(name) {
        this.channels = [...this.channels, { name, close: () => this.closed.push(name) }];
    }

    closeChannels() {
        throw new Error('the scheduler must not close every channel');
    }
}

class StoreStub extends Service {
    pushed = [];

    pushPayload(modelName, payload) {
        this.pushed.push([modelName, payload]);
    }

    peekAll() {
        return [];
    }
}

module('Unit | Controller | operations/scheduler/index', function (hooks) {
    setupTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:socket', SocketStub);
        this.owner.register('service:store', StoreStub);
        this.socket = this.owner.lookup('service:socket');
        this.controller = this.owner.lookup('controller:operations/scheduler/index');
        this.controller.drivers = [{ id: 'driver_a' }, { id: 'driver_b' }, {}];
    });

    test('it listens on each driver channel and not on a company orders channel', async function (assert) {
        await this.controller.subscribeToRealTimeUpdates();
        await this.controller.subscribeToRealTimeUpdates();

        assert.deepEqual(this.socket.listened, ['driver.driver_a', 'driver.driver_b'], 'one listener per driver, opened once');
        assert.notOk(
            this.socket.listened.some((name) => name.startsWith('company.')),
            'the dead company orders channel is gone'
        );
    });

    test('teardown closes only the channels the board opened', async function (assert) {
        this.socket.subscribeNow('chat_channel.abc');
        await this.controller.subscribeToRealTimeUpdates();

        this.controller.unsubscribeFromRealTimeUpdates();
        await settled();

        assert.deepEqual(this.socket.closed.sort(), ['driver.driver_a', 'driver.driver_b'], 'chat and other channels stay open');

        this.controller.unsubscribeFromRealTimeUpdates();
        await settled();
        assert.strictEqual(this.socket.closed.length, 2, 'a second teardown has nothing to close');
    });

    test('teardown also closes a channel the socket subscribed after teardown began', async function (assert) {
        this.socket.deferred.add('driver.driver_b');
        await this.controller.subscribeToRealTimeUpdates();

        this.controller.unsubscribeFromRealTimeUpdates();
        assert.deepEqual(this.socket.closed, ['driver.driver_a']);

        this.socket.subscribeNow('driver.driver_b');
        await settled();

        assert.deepEqual(this.socket.closed, ['driver.driver_a', 'driver.driver_b']);
    });

    test('the delayed sweep leaves a channel the board opened again', async function (assert) {
        this.socket.deferred.add('driver.driver_b');
        await this.controller.subscribeToRealTimeUpdates();
        this.controller.unsubscribeFromRealTimeUpdates();

        // The board is entered again before the sweep runs.
        await this.controller.subscribeToRealTimeUpdates();
        this.socket.subscribeNow('driver.driver_b');
        await settled();

        assert.deepEqual(this.socket.closed, ['driver.driver_a']);
    });

    test('teardown prefers closing channels by name when the socket service supports it', async function (assert) {
        const closedByName = [];
        this.socket.closeChannel = (name) => closedByName.push(name);
        await this.controller.subscribeToRealTimeUpdates();

        this.controller.unsubscribeFromRealTimeUpdates();
        await settled();

        assert.deepEqual(closedByName, ['driver.driver_a', 'driver.driver_b']);
        assert.deepEqual(this.socket.closed, []);
    });

    test('driver events update the store but location pings do not', async function (assert) {
        const store = this.owner.lookup('service:store');
        await this.controller.subscribeToRealTimeUpdates();

        const callback = this.socket.callbacks['driver.driver_a'];
        callback({ event: 'driver.location_changed', data: { id: 'driver_a' } });
        callback({ event: 'driver.updated', data: {} });
        callback({ event: 'driver.updated', data: { id: 'driver_a', status: 'active' } });

        assert.deepEqual(store.pushed, [['driver', { driver: { id: 'driver_a', status: 'active' } }]]);
    });
});
