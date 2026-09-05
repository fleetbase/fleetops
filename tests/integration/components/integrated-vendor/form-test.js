import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, findAll, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';
import stubFormInputs, { makeRecord } from 'dummy/tests/helpers/stub-form-inputs';

module('Integration | Component | integrated-vendor/form', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        stubFormInputs(this.owner);
        const test = this;
        this.allow = true;
        this.owner.register(
            'service:abilities',
            class extends Service {
                can() {
                    return test.allow;
                }
            }
        );
    });

    test('it renders provider identity, credential inputs and option inputs or selects', async function (assert) {
        this.set(
            'resource',
            makeRecord('integrated-vendor', {
                provider_options: {
                    logo: '/logo.png',
                    code: 'shippo',
                    name: 'Shippo',
                    credential_params: [{ key: 'api_key' }, { key: 'api_secret' }],
                    option_params: [{ key: 'label_format', options: ['pdf', 'png'] }, { key: 'account_id' }],
                },
                credentials: { api_key: 'k', api_secret: 's' },
                options: { label_format: 'pdf', account_id: 'acc' },
                host: 'https://api.goshippo.com',
                namespace: 'v1',
                webhook_url: 'https://hook',
                sandbox: true,
            })
        );

        await render(hbs`<IntegratedVendor::Form @resource={{this.resource}} />`);

        assert.dom('img').hasAttribute('alt', 'shippo');
        assert.dom('h3').hasText('Shippo');
        const labels = findAll('.input-group label, label').map((element) => element.textContent.trim());
        assert.true(labels.includes('API Key') && labels.includes('API Secret'), 'credential params are humanized labels');
        assert.dom('select').exists({ count: 1 }, 'an option param with choices renders a select');
        assert.true(labels.includes('Account ID') || labels.includes('Account Id'), 'an option param without choices renders an input');
        assert.dom('input[type="checkbox"]').isChecked();
        const values = () => findAll('input').map((element) => element.value);
        for (const value of ['k', 's', 'acc']) {
            assert.true(values().includes(value), `${value} is bound to an input`);
        }
        assert.false(values().includes('v1'), 'the advanced options panel starts collapsed');

        await click(findAll('.next-content-panel-header-left').at(-1));
        for (const value of ['https://api.goshippo.com', 'v1', 'https://hook']) {
            assert.true(values().includes(value), `${value} is bound once the advanced panel opens`);
        }
    });

    test('a provider without params renders only the sandbox toggle and advanced options', async function (assert) {
        this.allow = false;
        this.set(
            'resource',
            makeRecord(
                'integrated-vendor',
                { provider_options: { name: 'DHL', credential_params: [], option_params: [] }, sandbox: false, host: 'h', namespace: 'n', webhook_url: 'w' },
                { isNew: false }
            )
        );

        await render(hbs`<IntegratedVendor::Form @resource={{this.resource}} />`);

        assert.dom('select').doesNotExist();
        assert.dom('input[type="checkbox"]').isNotChecked().isDisabled();
        assert.strictEqual(findAll('input:not([type="checkbox"])').length, 0, 'no credential or option inputs, and the advanced panel starts collapsed');

        const advancedPanel = findAll('.next-content-panel-wrapper').find((element) => element.textContent.includes('Advanced'));
        assert.ok(advancedPanel, 'the advanced options panel renders');
        await click(advancedPanel.querySelector('.next-content-panel-header-left'));

        const textInputs = findAll('input:not([type="checkbox"])');
        assert.deepEqual(
            textInputs.map((element) => element.value),
            ['h', 'n', 'w'],
            'host, namespace and webhook'
        );
        assert.true(
            textInputs.every((element) => element.disabled),
            'all disabled without write access'
        );
    });
});
