import Service from '@ember/service';
import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

let posted;

class FetchStub extends Service {
    post(endpoint, payload, options) {
        posted = { endpoint, payload, options };
        return Promise.resolve({ status: 'ok', driver: { user_uuid: 'user-1', is_staff_linked: false, login_status: 'active' } });
    }
}

class NotificationsStub extends Service {
    success() {}
    serverError() {}
}

module('Integration | Component | modals/reset-profile-credentials', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        posted = null;
        this.owner.register('service:fetch', FetchStub);
        this.owner.register('service:notifications', NotificationsStub);
    });

    test('it posts the new password with the configured endpoint and payload', async function (assert) {
        const profile = {
            id: 'driver-1',
            name: 'Alex Driver',
            setProperties(values) {
                Object.assign(this, values);
            },
        };

        this.set('options', {
            profile,
            endpoint: 'drivers/driver-1/reset-credentials',
            payload: (subject) => ({ subject: subject.id }),
        });

        await render(hbs`<Modals::ResetProfileCredentials @options={{this.options}} @modalIsOpened={{true}} />`);

        const component = this.options;
        await component.confirm({
            startLoading: () => assert.step('loading'),
            stopLoading: () => assert.step('stopped'),
            done: () => assert.step('done'),
        });

        assert.strictEqual(posted.endpoint, 'drivers/driver-1/reset-credentials');
        assert.strictEqual(posted.payload.subject, 'driver-1');
        assert.true(posted.payload.send_credentials, 'sends credentials by default');
        assert.deepEqual(posted.options, { namespace: 'int/v1' });
        assert.strictEqual(profile.login_status, 'active', 'applies the login status from the response');
        assert.verifySteps(['loading', 'done']);
    });
});
