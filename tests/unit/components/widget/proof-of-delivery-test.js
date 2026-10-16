import Service from '@ember/service';
import { setOwner } from '@ember/application';
import { module, test } from 'qunit';
import { settled } from '@ember/test-helpers';
import { setupTest } from 'dummy/tests/helpers';
import WidgetProofOfDeliveryComponent from 'dummy/components/widget/proof-of-delivery';

// Records the URL the fetch service would request: the namespace option (the console's
// default is `int/v1`) joined to the path, plus the query string. Recording the bare
// path hid that `fleet-ops/proofs/recent` resolves to a URL the API does not route.
class FetchStub extends Service {
    requests = [];
    response = Promise.resolve({ proofs: [] });

    get(path, query = {}, options = {}) {
        const params = new URLSearchParams(query).toString();
        this.requests.push(`GET ${options.namespace ?? 'int/v1'}/${path}${params ? `?${params}` : ''}`);

        return this.response;
    }
}

module('Unit | Component | widget/proof-of-delivery', function (hooks) {
    setupTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:fetch', FetchStub);
        this.fetch = this.owner.lookup('service:fetch');
        // Glimmer only lets its component manager construct a component, so build the
        // instance on the prototype and run what the constructor runs.
        this.makeWidget = async () => {
            const component = Object.create(WidgetProofOfDeliveryComponent.prototype);
            setOwner(component, this.owner);
            component.load.perform();
            await settled();

            return component;
        };
    });

    test('it registers under the dashboard widget id', function (assert) {
        assert.strictEqual(WidgetProofOfDeliveryComponent.widgetId, 'fleet-ops-proof-of-delivery-widget');
    });

    test('loading asks for the latest photos and keeps them', async function (assert) {
        const proofs = [{ id: 'proof_1', order_id: 'order_1', url: 'https://cdn.test/1.png' }];
        this.fetch.response = Promise.resolve({ proofs });

        const component = await this.makeWidget();

        // `GET int/v1/proofs/recent` is the `proofs` resource route in server/src/routes.php;
        // it is not under the `fleet-ops` prefix group.
        assert.deepEqual(this.fetch.requests, ['GET int/v1/proofs/recent?limit=12']);
        assert.deepEqual(component.proofs, proofs);
        assert.strictEqual(component.error, null);
    });

    test('an empty response leaves no proofs', async function (assert) {
        this.fetch.response = Promise.resolve(null);

        const component = await this.makeWidget();

        assert.deepEqual(component.proofs, []);
        assert.deepEqual(component.photos, []);
    });

    test('a failed load reports the error', async function (assert) {
        this.fetch.response = Promise.reject(new Error('Nope'));
        const failing = await this.makeWidget();
        assert.strictEqual(failing.error, 'Nope');

        this.fetch.response = Promise.reject({});
        const silent = await this.makeWidget();
        assert.strictEqual(silent.error, 'Failed to load');
    });

    test('photos skip proofs without a url and carry a relative capture time', async function (assert) {
        const component = await this.makeWidget();
        const capturedAt = new Date(Date.now() - 5 * 60 * 1000).toISOString();
        component.proofs = [
            { id: 'proof_1', order_id: 'order_1', url: 'https://cdn.test/1.png', captured_at: capturedAt },
            { id: 'proof_2', order_id: 'order_2', url: null, captured_at: capturedAt },
            null,
            { id: 'proof_3', order_id: null, url: 'https://cdn.test/3.png', captured_at: null },
        ];

        const photos = component.photos;

        assert.deepEqual(
            photos.map((photo) => photo.id),
            ['proof_1', 'proof_3']
        );
        assert.strictEqual(photos[0].timeAgo, '5 minutes ago');
        assert.strictEqual(photos[0].order_id, 'order_1', 'keeps the proof fields');
        assert.strictEqual(photos[1].timeAgo, null, 'no capture time, no relative time');

        component.proofs = null;
        assert.deepEqual(component.photos, []);
    });

    test('time ago ignores invalid dates', async function (assert) {
        const component = await this.makeWidget();

        assert.strictEqual(component.timeAgo('not a date'), null);
        assert.strictEqual(component.timeAgo(undefined), null);
    });

    test('order links resolve from the host dashboard and inside the engine', async function (assert) {
        const component = await this.makeWidget();

        assert.strictEqual(component.orderRoute, 'console.fleet-ops.operations.orders.index.details');

        this.owner.mountPoint = 'console.fleet-ops';
        try {
            assert.strictEqual(component.orderRoute, 'operations.orders.index.details');
        } finally {
            delete this.owner.mountPoint;
        }
    });

    test('opening a photo opens its url in a new tab', async function (assert) {
        const component = await this.makeWidget();
        const originalOpen = window.open;
        const opened = [];
        window.open = (...args) => opened.push(args);

        try {
            component.openPhoto({ url: 'https://cdn.test/1.png' });
            component.openPhoto({ url: null });
            component.openPhoto(undefined);
        } finally {
            window.open = originalOpen;
        }

        assert.deepEqual(opened, [['https://cdn.test/1.png', '_blank', 'noopener,noreferrer']]);
    });
});
