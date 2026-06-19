import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import Service from '@ember/service';
import { A } from '@ember/array';

class FetchStub extends Service {
    calls = [];

    get(...args) {
        this.calls.push(args);

        return Promise.resolve(
            A([
                {
                    id: 'service_rate_test',
                    service_name: 'Test Rate',
                },
            ])
        );
    }
}

module('Unit | Service | service-rate-actions', function (hooks) {
    setupTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:fetch', FetchStub);
    });

    test('it exists', function (assert) {
        let service = this.owner.lookup('service:service-rate-actions');
        assert.ok(service);
    });

    test('queryServiceRatesForOrder skips incomplete route coordinates', async function (assert) {
        const service = this.owner.lookup('service:service-rate-actions');
        const fetch = this.owner.lookup('service:fetch');

        let emptyResult = await service.queryServiceRatesForOrder.perform({
            payload: {
                payloadCoordinates: [],
            },
        });
        let singlePointResult = await service.queryServiceRatesForOrder.perform({
            payload: {
                payloadCoordinates: [[103.8845049, 1.3621663]],
            },
        });

        assert.deepEqual(emptyResult, []);
        assert.deepEqual(singlePointResult, []);
        assert.strictEqual(fetch.calls.length, 0, 'does not request service rates without a complete route');
    });

    test('queryServiceRatesForOrder requests service rates for complete array route coordinates', async function (assert) {
        const service = this.owner.lookup('service:service-rate-actions');
        const fetch = this.owner.lookup('service:fetch');
        const orderConfig = {
            get(key) {
                return key === 'key' ? 'delivery' : undefined;
            },
        };

        let result = await service.queryServiceRatesForOrder.perform({
            facilitator: null,
            order_config: orderConfig,
            payload: {
                payloadCoordinates: [
                    [103.8845049, 1.3621663],
                    [103.86458, 1.353151],
                ],
            },
        });

        assert.strictEqual(fetch.calls.length, 1);
        assert.strictEqual(fetch.calls[0][0], 'service-rates/for-route');
        assert.deepEqual(fetch.calls[0][1], {
            coordinates: '1.3621663,103.8845049;1.353151,103.86458',
            facilitator: null,
            service_type: 'delivery',
        });
        assert.strictEqual(result[0].id, 'all', 'prepends the quote-all service-rate option after a successful request');
    });

    test('queryServiceRatesForOrder ignores invalid route coordinates before requesting', async function (assert) {
        const service = this.owner.lookup('service:service-rate-actions');
        const fetch = this.owner.lookup('service:fetch');

        await service.queryServiceRatesForOrder.perform({
            facilitator: null,
            payload: {
                payloadCoordinates: [null, [103.8845049, 1.3621663], ['bad', 1.2], '', [103.86458, 1.353151]],
            },
        });

        assert.strictEqual(fetch.calls.length, 1);
        assert.deepEqual(fetch.calls[0][1], {
            coordinates: '1.3621663,103.8845049;1.353151,103.86458',
            facilitator: null,
            service_type: undefined,
        });
    });

    test('queryServiceRatesForOrder still supports legacy string route coordinates', async function (assert) {
        const service = this.owner.lookup('service:service-rate-actions');
        const fetch = this.owner.lookup('service:fetch');

        await service.queryServiceRatesForOrder.perform({
            facilitator: null,
            payload: {
                payloadCoordinates: ['1.3621663,103.8845049', '1.353151,103.86458'],
            },
        });

        assert.strictEqual(fetch.calls.length, 1);
        assert.strictEqual(fetch.calls[0][1].coordinates, '1.3621663,103.8845049;1.353151,103.86458');
    });
});
