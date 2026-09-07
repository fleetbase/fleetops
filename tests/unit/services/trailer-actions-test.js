import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import Service from '@ember/service';

class FetchStub extends Service {
    posts = [];

    post(url, body) {
        this.posts.push({ url, body });
        return Promise.resolve({ status: 'ok' });
    }
}

class NotificationsStub extends Service {
    messages = [];
    success(message) {
        this.messages.push(['success', message]);
    }
    warning(message) {
        this.messages.push(['warning', message]);
    }
    serverError(error) {
        this.messages.push(['error', error?.message ?? error]);
    }
}

class ModalsManagerStub extends Service {
    shown = [];
    confirmed = [];

    show(name, options) {
        this.shown.push({ name, options });
        return Promise.resolve();
    }

    confirm(options) {
        this.confirmed.push(options);
        return Promise.resolve();
    }
}

module('Unit | Service | trailer-actions', function (hooks) {
    setupTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:fetch', FetchStub);
        this.owner.register('service:notifications', NotificationsStub);
        this.owner.register('service:modals-manager', ModalsManagerStub);
    });

    test('it creates a first-class Trailer with stable lifecycle defaults', function (assert) {
        const service = this.owner.lookup('service:trailer-actions');
        const trailer = service.createNewInstance();

        assert.strictEqual(service.modelName, 'trailer');
        assert.strictEqual(trailer.status, 'available');
        assert.strictEqual(trailer.asset_class, 'trailer');
        assert.strictEqual(trailer.measurement_system, 'metric');
        assert.strictEqual(typeof service.transition.create, 'function');
        assert.strictEqual(typeof service.panel.view, 'function');
        assert.strictEqual(typeof service.modal.create, 'function');
    });

    test('it exposes the full set of Trailer detail tabs for the context panel', function (assert) {
        const service = this.owner.lookup('service:trailer-actions');
        const keys = service.panelTabs.map((tab) => tab.key);

        assert.deepEqual(keys, ['overview', 'positions', 'devices', 'equipment', 'connections', 'schedules', 'work-orders', 'maintenance-history']);
        assert.strictEqual(service.panelTabs.find((tab) => tab.key === 'devices').component, 'device/manager');
    });

    test('attaching to a vehicle posts the trailer endpoint with the vehicle and towing position', async function (assert) {
        const service = this.owner.lookup('service:trailer-actions');
        const fetch = this.owner.lookup('service:fetch');
        const modals = this.owner.lookup('service:modals-manager');
        const store = this.owner.lookup('service:store');
        const trailer = store.createRecord('trailer', { name: 'Reefer 12', attachment_state: 'detached' });
        trailer.reload = () => Promise.resolve(trailer);
        let callbackArgs;

        service.attachVehicle(trailer, { callback: (...args) => (callbackArgs = args) });

        const modal = modals.shown[0];
        assert.strictEqual(modal.name, 'modals/attach-trailer');
        assert.strictEqual(modal.options.position, 1);

        const vehicle = { id: 'vehicle-1', displayName: 'Truck 1' };
        const options = { ...modal.options, selectedVehicle: vehicle, position: '2' };
        await modal.options.confirm({
            getOption: (key) => options[key],
            startLoading() {},
            stopLoading() {},
            done() {},
        });

        assert.deepEqual(fetch.posts, [{ url: `trailers/${trailer.id}/attach`, body: { vehicle: 'vehicle-1', position: 2 } }]);
        assert.strictEqual(callbackArgs[1], vehicle);
    });

    test('detaching an unattached trailer warns instead of calling the API', function (assert) {
        const service = this.owner.lookup('service:trailer-actions');
        const fetch = this.owner.lookup('service:fetch');
        const notifications = this.owner.lookup('service:notifications');
        const store = this.owner.lookup('service:store');
        const trailer = store.createRecord('trailer', { name: 'Flatbed 3', attachment_state: 'detached' });

        service.detachVehicle(trailer);

        assert.deepEqual(fetch.posts, []);
        assert.strictEqual(notifications.messages[0][0], 'warning');
    });

    test('detaching an attached trailer confirms and posts the detach endpoint', async function (assert) {
        const service = this.owner.lookup('service:trailer-actions');
        const fetch = this.owner.lookup('service:fetch');
        const modals = this.owner.lookup('service:modals-manager');
        const store = this.owner.lookup('service:store');
        const trailer = store.createRecord('trailer', { name: 'Flatbed 3', attachment_state: 'attached', current_vehicle_name: 'Truck 1' });
        trailer.reload = () => Promise.resolve(trailer);

        service.detachVehicle(trailer);

        const confirmation = modals.confirmed[0];
        assert.ok(confirmation.title.includes('Flatbed 3'));
        await confirmation.confirm({ startLoading() {}, stopLoading() {}, done() {} });

        assert.deepEqual(fetch.posts, [{ url: `trailers/${trailer.id}/detach`, body: undefined }]);
    });
});
