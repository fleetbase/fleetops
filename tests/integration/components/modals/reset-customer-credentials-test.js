import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, fillIn, findAll, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';

function fakeModal() {
    const modal = { events: [] };
    modal.startLoading = () => modal.events.push('startLoading');
    modal.stopLoading = () => modal.events.push('stopLoading');
    modal.done = () => modal.events.push('done');
    return modal;
}

module('Integration | Component | modals/reset-customer-credentials', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        const calls = (this.calls = []);
        const test = this;
        this.postFails = false;
        this.owner.register(
            'service:fetch',
            class extends Service {
                async post(url, body, options) {
                    calls.push(['post', url, body, options]);
                    if (test.postFails) {
                        throw new Error('weak password');
                    }
                }
            }
        );
        this.owner.register(
            'service:notifications',
            class extends Service {
                success(message) {
                    calls.push(['success', message]);
                }

                serverError(error) {
                    calls.push(['serverError', error.message]);
                }
            }
        );
    });

    test('it configures the modal and resets the credentials from the form', async function (assert) {
        this.set('options', { customer: { id: 'customer_1', name: 'Acme' }, onPasswordResetComplete: () => this.calls.push(['onPasswordResetComplete']) });

        await render(hbs`<Modals::ResetCustomerCredentials @modalIsOpened={{true}} @options={{this.options}} />`);

        assert.strictEqual(this.options.title, 'Reset Customer Credentials');
        assert.strictEqual(this.options.acceptButtonText, 'Reset Credentials');
        assert.true(this.options.declineButtonHidden);
        assert.dom().includesText('You are about to reset the password for Acme');

        const [password, confirmation] = findAll('input[type="password"]');
        await fillIn(password, 'hunter22');
        await fillIn(confirmation, 'hunter22');
        await click('.fleetbase-checkbox');

        const modal = fakeModal();
        await this.options.confirm(modal);
        assert.deepEqual(this.calls, [
            ['post', 'customers/reset-credentials', { customer: 'customer_1', password: 'hunter22', password_confirmation: 'hunter22', send_credentials: false }, { namespace: 'int/v1' }],
            ['success', 'Customer password reset.'],
            ['onPasswordResetComplete'],
        ]);
        assert.deepEqual(modal.events, ['startLoading', 'done']);
    });

    test('a failed reset is reported and the modal keeps waiting; no completion callback is fine', async function (assert) {
        this.set('options', { customer: { id: 'customer_2', name: 'Beta' } });
        this.postFails = true;

        await render(hbs`<Modals::ResetCustomerCredentials @modalIsOpened={{true}} @options={{this.options}} />`);

        const modal = fakeModal();
        await this.options.confirm(modal);
        assert.deepEqual(this.calls.at(-1), ['serverError', 'weak password']);
        assert.deepEqual(modal.events, ['startLoading', 'stopLoading']);

        this.postFails = false;
        const second = fakeModal();
        await this.options.confirm(second);
        assert.deepEqual(this.calls.at(-1), ['success', 'Customer password reset.']);
        assert.deepEqual(second.events, ['startLoading', 'done']);
        assert.strictEqual(this.calls.at(-2)[2].send_credentials, true, 'credentials are sent by default');
    });
});
