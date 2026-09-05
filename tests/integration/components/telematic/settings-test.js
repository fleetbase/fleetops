import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { fillIn, render } from '@ember/test-helpers';
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

/**
 * The form renders the connection's own fields alongside the provider's credential fields, so a
 * credential input has to be picked out by the label of the group holding it.
 */
function credentialInput(test, label) {
    return [...test.element.querySelectorAll('.input-group')].find((group) => group.querySelector('label')?.textContent.trim().startsWith(label)).querySelector('input');
}

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

    test('typing a credential merges it into the ones already saved', async function (assert) {
        this.set(
            'telematic',
            makeResource({
                provider: 'safee',
                provider_descriptor: SAFEE_DESCRIPTOR,
                credentials: { username: 'fleet-ops', password: null },
            })
        );

        await render(hbs`<Telematic::Settings @resource={{this.telematic}} />`);

        await fillIn(credentialInput(this, 'Username'), 'new-operator');
        assert.deepEqual(this.telematic.credentials, { username: 'new-operator', password: null }, 'the edited field is merged over the saved set');

        await fillIn('details input', 'https://fms.example.test');
        assert.deepEqual(this.telematic.credentials, { username: 'new-operator', password: null, server_uri: 'https://fms.example.test' }, 'an advanced field is merged in the same way');
    });

    test('a connection with no credentials yet starts from an empty set', async function (assert) {
        this.set(
            'telematic',
            makeResource({
                provider: 'flespi',
                provider_descriptor: {
                    key: 'flespi',
                    label: 'Flespi',
                    required_fields: [{ name: 'token', label: 'Token', required: true }],
                },
            })
        );

        await render(hbs`<Telematic::Settings @resource={{this.telematic}} />`);

        await fillIn(credentialInput(this, 'Token'), 'abc123');
        assert.deepEqual(this.telematic.credentials, { token: 'abc123' });
    });

    test('the webhook url carries the connection id, joined onto any query the provider already has', async function (assert) {
        const renderWith = async (webhookUrl, publicId) => {
            this.set(
                'telematic',
                makeResource({
                    provider: 'safee',
                    public_id: publicId,
                    provider_descriptor: { ...SAFEE_DESCRIPTOR, supports_webhooks: true, webhook_url: webhookUrl },
                    credentials: {},
                })
            );
            await render(hbs`<Telematic::Settings @resource={{this.telematic}} />`);
            return this.element.querySelector('input[readonly]')?.value ?? null;
        };

        assert.strictEqual(
            await renderWith('https://hooks.example.test/safee', 'telematic_abc123'),
            'https://hooks.example.test/safee?telematic=telematic_abc123',
            'a plain url opens the query string'
        );
        assert.strictEqual(
            await renderWith('https://hooks.example.test/safee?v=2', 'telematic_abc123'),
            'https://hooks.example.test/safee?v=2&telematic=telematic_abc123',
            'an existing query string is appended to'
        );
        assert.strictEqual(await renderWith('https://hooks.example.test/safee', undefined), null, 'a connection with no public id has no webhook url');
        assert.dom().includesText('Webhook URL unavailable until this connection has a public ID.');
        assert.strictEqual(await renderWith(undefined, 'telematic_abc123'), null, 'nor does a provider that publishes no webhook url');
    });
});
