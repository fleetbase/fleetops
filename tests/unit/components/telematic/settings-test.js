import { module, test } from 'qunit';
import TelematicSettingsComponent from 'dummy/components/telematic/settings';

const REQUIRED_FIELDS = [
    { name: 'api_key', label: 'API Key' },
    { name: 'server_uri', label: 'Server URI', advanced: true, is_endpoint: true, default_value: 'https://api.safee.com' },
    { name: 'base_url', label: 'Base URL', is_endpoint: true, default_value: 'https://api.afaqy.com' },
    { name: 'realm_id', label: 'Realm', advanced: true },
];

function makeSettings(resource) {
    const component = Object.create(TelematicSettingsComponent.prototype);
    component.args = { resource };

    return component;
}

module('Unit | Component | telematic/settings', function () {
    test('endpoint and advanced fields are separated from the primary credential fields', function (assert) {
        const component = makeSettings({ provider_descriptor: { required_fields: REQUIRED_FIELDS } });

        assert.deepEqual(
            component.credentialFields.map((field) => field.name),
            ['api_key']
        );
        assert.deepEqual(
            component.advancedCredentialFields.map((field) => field.name),
            ['server_uri', 'base_url', 'realm_id']
        );
        assert.true(component.hasAdvancedCredentialFields);
    });

    test('providers without advanced fields do not render the advanced section', function (assert) {
        const component = makeSettings({ provider_descriptor: { required_fields: [{ name: 'api_key' }] } });

        assert.deepEqual(component.advancedCredentialFields, []);
        assert.false(component.hasAdvancedCredentialFields);
    });

    test('a provider without a descriptor is safe to render', function (assert) {
        const component = makeSettings({});

        assert.deepEqual(component.credentialFields, []);
        assert.deepEqual(component.advancedCredentialFields, []);
        assert.false(component.hasAdvancedCredentialFields);
    });

    test('webhook url requires both a provider endpoint and a public id', function (assert) {
        const provider = { webhook_url: 'https://api.example.test/telematics/webhook' };

        assert.strictEqual(
            makeSettings({ provider_descriptor: provider, public_id: 'telematic_abc123' }).webhookUrl,
            'https://api.example.test/telematics/webhook?telematic=telematic_abc123'
        );
        assert.strictEqual(makeSettings({ provider_descriptor: provider }).webhookUrl, null);
        assert.false(makeSettings({ provider_descriptor: provider }).hasWebhookUrl);
    });
});
