import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, fillIn, findAll, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';
import { makeRecord } from 'dummy/tests/helpers/stub-form-inputs';

function buttonByText(pattern) {
    return findAll('button').find((button) => pattern.test(button.textContent));
}

module('Integration | Component | order/details/notes', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        const calls = (this.calls = []);
        const test = this;
        this.saveFails = false;
        this.owner.register(
            'service:abilities',
            class extends Service {
                can() {
                    return true;
                }

                cannot() {
                    return false;
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
        this.resource = makeRecord('order', { id: 'order_1', notes: '' }, { isNew: false });
        this.resource.persistProperty = async (key, value) => {
            calls.push(['persist', key, value]);
            if (test.saveFails) {
                throw new Error('offline');
            }
        };
    });

    test('editing a note saves it, and failures keep the editor open', async function (assert) {
        this.set('resource', this.resource);

        await render(hbs`<Order::Details::Notes @resource={{this.resource}} />`);

        assert.dom().includesText('No notes');
        assert.notOk(buttonByText(/Edit/).disabled);

        await click(buttonByText(/Edit/));
        assert.dom('textarea').exists();
        assert.ok(buttonByText(/Edit/).disabled, 'editing disables the edit action');

        await fillIn('textarea', 'Ring the bell twice.');
        await click(buttonByText(/Save Order Note/));
        assert.deepEqual(this.calls, [
            ['persist', 'notes', 'Ring the bell twice.'],
            ['success', 'Order notes updated.'],
        ]);
        assert.dom('textarea').doesNotExist();
        assert.dom('p.font-mono').hasText('Ring the bell twice.');

        this.saveFails = true;
        await click(buttonByText(/Edit/));
        await click(buttonByText(/Save Order Note/));
        assert.deepEqual(this.calls.at(-1), ['serverError', 'offline']);
        assert.dom('textarea').exists('the editor stays open after a failure');

        await click(buttonByText(/Cancel/));
        assert.dom('textarea').doesNotExist();
    });
});
