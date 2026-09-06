import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { findAll, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

function fieldNames() {
    return findAll('.field-name').map((element) => element.textContent.trim());
}

module('Integration | Component | integrated-vendor/details', function (hooks) {
    setupRenderingTest(hooks);

    test('it renders the provider identity, connection details and options', async function (assert) {
        this.set('vendor', {
            provider_settings: { logo: '/logo.png', code: 'shippo', name: 'Shippo' },
            sandbox: true,
            host: 'https://api.goshippo.com',
            namespace: 'v1',
            options: { api_key: 'secret', label_format: 'pdf' },
        });

        await render(hbs`<IntegratedVendor::Details @vendor={{this.vendor}} />`);

        assert.dom('img').hasAttribute('alt', 'shippo');
        assert.dom('h3').hasText('Shippo');
        assert.dom(this.element).includesText('Yes').includesText('https://api.goshippo.com').includesText('v1').includesText('secret').includesText('pdf');
        assert.deepEqual(fieldNames().slice(-2), ['API Key', 'Label Format'], 'option keys are humanized');
    });

    test('it reports non-sandbox vendors and skips options that are not an object', async function (assert) {
        this.set('vendor', { provider_settings: { name: 'DHL' }, sandbox: false, options: 'nope' });

        await render(hbs`<IntegratedVendor::Details @vendor={{this.vendor}} />`);

        assert.dom(this.element).includesText('No');
        assert.strictEqual(fieldNames().length, 3, 'only sandbox, host and namespace');
        assert.strictEqual(findAll('.field-value').filter((element) => element.textContent.trim() === '-').length, 2, 'host and namespace are dashed');
    });
});
