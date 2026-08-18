import { module, test } from 'qunit';
import TelematicFormComponent from 'dummy/components/telematic/form';

function makeResource(initial = {}) {
    return {
        ...initial,
        set(key, value) {
            this[key] = value;
        },
        setProperties(values) {
            Object.assign(this, values);
        },
    };
}

function makeFormWithProvider(provider) {
    return Object.create(TelematicFormComponent.prototype, {
        selectedProvider: { value: provider, writable: true },
    });
}

module('Unit | Component | telematic/form', function () {
    test('provider credential defaults include advanced endpoint values', function (assert) {
        const provider = {
            required_fields: [
                { name: 'server_uri', advanced: true, is_endpoint: true, default_value: 'https://api.safee.com' },
                { name: 'client_id' },
                { name: 'password', default_value: null },
            ],
        };

        const credentials = TelematicFormComponent.prototype.buildProviderCredentials(provider);

        assert.deepEqual(credentials, {
            server_uri: 'https://api.safee.com',
            client_id: null,
            password: null,
        });
    });

    test('endpoint and advanced fields are separated from the primary credential fields', function (assert) {
        const component = makeFormWithProvider({
            required_fields: [
                { name: 'api_key' },
                { name: 'server_uri', advanced: true, is_endpoint: true, default_value: 'https://api.safee.com' },
                { name: 'base_url', is_endpoint: true, default_value: 'https://api.afaqy.com' },
                { name: 'realm_id', advanced: true },
            ],
        });

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
        const component = makeFormWithProvider({ required_fields: [{ name: 'api_key' }] });

        assert.deepEqual(component.advancedCredentialFields, []);
        assert.false(component.hasAdvancedCredentialFields);
    });

    test('editing server uri updates resource credentials', function (assert) {
        const resource = makeResource({
            credentials: {
                server_uri: 'https://api.safee.com',
                client_id: 'api',
            },
        });

        TelematicFormComponent.prototype.setCredential.call(
            {
                args: { resource },
                resetConnectionTest() {},
            },
            { name: 'server_uri' },
            { target: { value: 'https://fms.example.test' } }
        );

        assert.deepEqual(resource.credentials, {
            server_uri: 'https://fms.example.test',
            client_id: 'api',
        });
    });

    test('connection test payload includes custom server uri', function (assert) {
        const resource = makeResource({
            id: 'telematic_1',
            credentials: {
                server_uri: 'https://fms.example.test',
                realm_id: 'dsco',
            },
        });

        const payload = TelematicFormComponent.prototype.getConnectionTestPayload.call({
            args: { resource },
        });

        assert.deepEqual(payload, {
            credentials: {
                server_uri: 'https://fms.example.test',
                realm_id: 'dsco',
            },
            telematic_id: 'telematic_1',
        });
    });
});
