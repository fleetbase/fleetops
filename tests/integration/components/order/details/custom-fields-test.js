import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';
import registerTemplateOnly from 'dummy/tests/helpers/register-template-only';

module('Integration | Component | order/details/custom-fields', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        registerTemplateOnly(
            this.owner,
            'custom-field/yield',
            hbs`<div data-test-custom-fields data-test-editable={{@editable}}><button type="button" data-test-save {{on "click" @onChange}}></button></div>`
        );
        const calls = (this.calls = []);
        this.owner.register(
            'service:notifications',
            class extends Service {
                serverError(error) {
                    calls.push(['serverError', error.message]);
                }
            }
        );
        const test = this;
        this.saveFails = false;
        this.resource = {
            status: 'created',
            custom_field_values: [{ id: 'cfv_1' }],
            order_config: { id: 'config_1' },
            save: async () => {
                calls.push(['save']);
                if (test.saveFails) {
                    throw new Error('validation failed');
                }
            },
        };
    });

    test('a change reports the values to the parent and saves the order', async function (assert) {
        this.set('onChange', (values) => this.calls.push(['onChange', values]));

        await render(hbs`<Order::Details::CustomFields @resource={{this.resource}} @onChange={{this.onChange}} />`);
        assert.dom('[data-test-custom-fields]').hasAttribute('data-test-editable');

        await click('[data-test-save]');
        assert.deepEqual(this.calls, [['onChange', [{ id: 'cfv_1' }]], ['save']]);

        this.saveFails = true;
        await click('[data-test-save]');
        assert.deepEqual(this.calls.at(-1), ['serverError', 'validation failed']);
    });

    test('without a parent callback it only saves, and a canceled order is not editable', async function (assert) {
        this.resource.status = 'canceled';

        await render(hbs`<Order::Details::CustomFields @resource={{this.resource}} />`);
        assert.dom('[data-test-custom-fields]').doesNotHaveAttribute('data-test-editable');

        await click('[data-test-save]');
        assert.deepEqual(this.calls, [['save']]);
    });
});
