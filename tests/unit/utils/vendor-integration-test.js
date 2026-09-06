import { module, test } from 'qunit';
import { normalizeToArray, extractKey, buildNullObject, buildWebhookUrl, normalizeProvider, buildIntegrationPayload } from '@fleetbase/fleetops-engine/utils/vendor-integration';

module('Unit | Utility | vendor-integration', function () {
    test('normalizeToArray accepts arrays, toArray objects, iterables and nothing', function (assert) {
        const array = [1, 2];

        assert.strictEqual(normalizeToArray(array), array);
        assert.deepEqual(normalizeToArray(null), []);
        assert.deepEqual(normalizeToArray({ toArray: () => [3] }), [3]);
        assert.deepEqual(normalizeToArray(new Set([4, 5])), [4, 5]);
        assert.deepEqual(
            normalizeToArray({
                [Symbol.iterator]() {
                    throw new Error('not iterable after all');
                },
            }),
            [],
            'a broken iterable degrades to an empty list'
        );
    });

    test('extractKey reads strings and key-like object fields with a fallback', function (assert) {
        assert.strictEqual(extractKey(' api_key '), 'api_key');
        assert.strictEqual(extractKey('   ', 'fallback'), 'fallback');
        assert.strictEqual(extractKey({ key: 'k' }), 'k');
        assert.strictEqual(extractKey({ name: 'n' }), 'n');
        assert.strictEqual(extractKey({ code: 'c' }), 'c');
        assert.strictEqual(extractKey({}, 'fallback'), 'fallback');
        assert.strictEqual(extractKey({ key: 5 }, 'fallback'), 'fallback', 'a non-string key is ignored');
        assert.strictEqual(extractKey(7, 'fallback'), 'fallback');
        assert.strictEqual(extractKey(null), undefined);
    });

    test('buildNullObject maps every usable key to null', function (assert) {
        assert.deepEqual(buildNullObject(['api_key', { key: 'secret' }, {}, '']), { api_key: null, secret: null });
        assert.deepEqual(buildNullObject([{}], { keyFallback: 'option' }), { option: null });
        assert.deepEqual(buildNullObject(undefined), {});
    });

    test('buildWebhookUrl points at the provider listener endpoint', function (assert) {
        assert.true(buildWebhookUrl('shippo').endsWith('/listeners/shippo'));
    });

    test('normalizeProvider validates the provider shape', function (assert) {
        assert.strictEqual(normalizeProvider(null), null);
        assert.strictEqual(normalizeProvider('shippo'), null);
        assert.strictEqual(normalizeProvider({}), null);
        assert.strictEqual(normalizeProvider({ code: 42 }), null);
        assert.deepEqual(normalizeProvider({ code: 'shippo' }), { code: 'shippo', credentialParams: [], optionParams: [] });
        assert.deepEqual(normalizeProvider({ code: 'shippo', credential_params: ['token'], option_params: new Set([{ key: 'sandbox' }]) }), {
            code: 'shippo',
            credentialParams: ['token'],
            optionParams: [{ key: 'sandbox' }],
        });
    });

    test('buildIntegrationPayload assembles the integrated vendor attributes', function (assert) {
        const payload = buildIntegrationPayload({ code: 'shippo', credentialParams: ['token', {}], optionParams: [{ key: 'sandbox' }, {}] });

        assert.strictEqual(payload.provider, 'shippo');
        assert.true(payload.webhook_url.endsWith('/listeners/shippo'));
        assert.deepEqual(payload.credentials, { token: null, credential: null });
        assert.deepEqual(payload.options, { sandbox: null, option: null });
        assert.deepEqual(payload.credential_params, ['token', {}]);
        assert.deepEqual(payload.option_params, [{ key: 'sandbox' }, {}]);
    });
});
