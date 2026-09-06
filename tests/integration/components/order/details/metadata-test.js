import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, findAll, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';
import { AbilitiesStub } from 'dummy/tests/helpers/stub-form-inputs';

module('Integration | Component | order/details/metadata', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        const edited = (this.edited = []);
        this.owner.register('service:abilities', AbilitiesStub);
        this.owner.register(
            'service:order-actions',
            class extends Service {
                editMetadata(order) {
                    edited.push(order);
                }
            }
        );
    });

    test('it shows the order metadata and the edit action opens the metadata editor', async function (assert) {
        this.set('resource', { id: 'order_1', meta: { priority: 'high' } });

        await render(hbs`<Order::Details::Metadata @resource={{this.resource}} />`);

        assert.dom().includesText('Metadata');
        assert.dom().includesText('priority');
        assert.dom().includesText('high');
        assert.dom('.px-0i').exists('a populated meta drops the body padding');

        await click(findAll('button').find((button) => /Edit/.test(button.textContent)));
        assert.deepEqual(this.edited, [this.resource]);

        this.set('resource', { id: 'order_2', meta: {} });
        await render(hbs`<Order::Details::Metadata @resource={{this.resource}} />`);
        assert.dom('.px-0i').doesNotExist('an empty meta keeps the padding');
    });
});
