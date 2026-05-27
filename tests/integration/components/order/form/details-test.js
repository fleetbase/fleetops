import { module, test } from 'qunit';
import Service from '@ember/service';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | order/form/details', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        class OrderConfigActionsStub extends Service {
            allOrderConfigs = [];
            loadAll = {
                perform() {},
            };
        }

        this.owner.register('service:order-config-actions', OrderConfigActionsStub);
    });

    test('it marks required create-order detail fields', async function (assert) {
        this.set('resource', {
            facilitator: {
                isIntegratedVendor: false,
            },
            order_config: null,
            payload: {},
            pod_required: true,
            required_skills: [],
        });

        await render(hbs`<Order::Form::Details @resource={{this.resource}} />`);

        const requiredLabels = [...this.element.querySelectorAll('label.required')].map((label) => label.textContent.trim());

        assert.dom('label.required').exists({ count: 2 });
        assert.true(requiredLabels.includes('Order Type'));
        assert.true(requiredLabels.includes('Proof of Delivery'));
    });
});
