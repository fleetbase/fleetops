import Service from '@ember/service';
import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

const SAFEE_DESCRIPTOR = {
    key: 'safee',
    label: 'Safee',
    type: 'native',
    required_fields: [
        { name: 'username', label: 'Username', required: true },
        { name: 'password', label: 'Password', required: true, type: 'password' },
        { name: 'server_uri', label: 'Server URI', advanced: true, is_endpoint: true, required: false, default_value: 'https://api.safee.com' },
    ],
};

const FLESPI_DESCRIPTOR = {
    key: 'flespi',
    label: 'Flespi',
    type: 'native',
    required_fields: [{ name: 'token', label: 'Token', required: true }],
};

class FetchStub extends Service {
    get() {
        return Promise.resolve([SAFEE_DESCRIPTOR, FLESPI_DESCRIPTOR]);
    }
}

class NotificationsStub extends Service {
    serverError() {}
}

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

module('Integration | Component | telematic/form', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:fetch', FetchStub);
        this.owner.register('service:notifications', NotificationsStub);
    });

    test('endpoint overrides render inside the advanced section with provider defaults', async function (assert) {
        this.set(
            'telematic',
            makeResource({
                provider: 'safee',
                provider_descriptor: SAFEE_DESCRIPTOR,
                credentials: { username: null, password: null, server_uri: null },
            })
        );

        await render(hbs`<Telematic::Form @resource={{this.telematic}} />`);

        assert.dom('details summary').includesText('Advanced connection settings');
        assert.deepEqual(
            [...this.element.querySelectorAll('details input')].map((input) => input.value),
            ['https://api.safee.com'],
            'the endpoint override falls back to the provider default'
        );
        assert.dom('details').includesText('Server URI');
        assert.dom('details').doesNotIncludeText('Username');
        assert.dom().includesText('Username');
    });

    test('providers without endpoint overrides do not render the advanced section', async function (assert) {
        this.set(
            'telematic',
            makeResource({
                provider: 'flespi',
                provider_descriptor: FLESPI_DESCRIPTOR,
                credentials: { token: null },
            })
        );

        await render(hbs`<Telematic::Form @resource={{this.telematic}} />`);

        assert.dom('details').doesNotExist();
        assert.dom().doesNotIncludeText('Advanced connection settings');
        assert.dom().includesText('Token');
    });
});
