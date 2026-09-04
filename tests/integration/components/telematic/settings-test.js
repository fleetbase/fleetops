import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import registerTemplateOnly from 'dummy/tests/helpers/register-template-only';

const SAFEE_DESCRIPTOR = {
    key: 'safee',
    label: 'Safee',
    required_fields: [
        { name: 'username', label: 'Username', required: true },
        { name: 'password', label: 'Password', required: true, type: 'password' },
        { name: 'server_uri', label: 'Server URI', advanced: true, is_endpoint: true, required: false, default_value: 'https://api.safee.com' },
    ],
};

function makeResource(initial = {}) {
    return {
        ...initial,
        set(key, value) {
            this[key] = value;
        },
    };
}

module('Integration | Component | telematic/settings', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        registerTemplateOnly(this.owner, 'custom-field/yield', hbs`<div data-test-custom-fields></div>`);
    });

    test('endpoint overrides render inside the advanced section with provider defaults', async function (assert) {
        this.set(
            'telematic',
            makeResource({
                provider: 'safee',
                public_id: 'telematic_abc123',
                provider_descriptor: SAFEE_DESCRIPTOR,
                credentials: { username: 'fleet-ops', password: null },
            })
        );

        await render(hbs`<Telematic::Settings @resource={{this.telematic}} />`);

        assert.dom('details summary').includesText('Advanced connection settings');
        assert.deepEqual(
            [...this.element.querySelectorAll('details input')].map((input) => input.value),
            ['https://api.safee.com'],
            'the endpoint override falls back to the provider default'
        );
        assert.dom('details').includesText('Server URI');
        assert.dom('details').doesNotIncludeText('Username');
    });

    test('a saved endpoint override wins over the provider default', async function (assert) {
        this.set(
            'telematic',
            makeResource({
                provider: 'safee',
                provider_descriptor: SAFEE_DESCRIPTOR,
                credentials: { username: 'fleet-ops', server_uri: 'https://fms.example.test' },
            })
        );

        await render(hbs`<Telematic::Settings @resource={{this.telematic}} />`);

        assert.deepEqual(
            [...this.element.querySelectorAll('details input')].map((input) => input.value),
            ['https://fms.example.test']
        );
    });

    test('providers without endpoint overrides do not render the advanced section', async function (assert) {
        this.set(
            'telematic',
            makeResource({
                provider: 'flespi',
                provider_descriptor: {
                    key: 'flespi',
                    label: 'Flespi',
                    required_fields: [{ name: 'token', label: 'Token', required: true }],
                },
                credentials: { token: 'abc' },
            })
        );

        await render(hbs`<Telematic::Settings @resource={{this.telematic}} />`);

        assert.dom('details').doesNotExist();
        assert.dom().doesNotIncludeText('Advanced connection settings');
        assert.dom().includesText('Token');
    });
});
