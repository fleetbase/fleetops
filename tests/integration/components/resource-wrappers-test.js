import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import { initialize } from '@fleetbase/fleetops-engine/instance-initializers/register-fleetops-resource-descriptors';
import { buildFleetOpsResourceDescriptors } from '@fleetbase/fleetops-engine/utils/resource-descriptors';

/**
 * Every FleetOps resource has a pill, a summary, an identity cell and a
 * select option. Rendering each with a minimal record proves the wrapper
 * resolves and its descriptor tolerates sparse data.
 */
module('Integration | Component | resource wrappers', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        initialize(this.owner);
    });

    const keys = buildFleetOpsResourceDescriptors({ lookup: () => null }).map((descriptor) => descriptor.key);

    for (const key of keys) {
        test(`${key} renders its pill, summary, identity cell and select option`, async function (assert) {
            this.set('record', { resourceType: key, name: `A ${key}`, public_id: `${key}_1` });
            this.set('pill', `${key}/pill`);
            this.set('summary', `${key}/summary`);
            this.set('cell', `cell/${key}-identity`);
            this.set('option', `select-option/${key}`);

            await render(hbs`
                <div data-test-pill>{{component this.pill resource=this.record noPopover=true}}</div>
                <div data-test-summary>{{component this.summary resource=this.record showView=false}}</div>
                <div data-test-cell>{{component this.cell row=this.record column=(hash popover=false)}}</div>
                <div data-test-option>{{component this.option option=this.record compact=true}}</div>
            `);

            assert.dom('[data-test-pill] [data-test-resource-pill]').exists(`${key} pill`);
            assert.dom('[data-test-summary] [data-test-resource-summary-title]').exists(`${key} summary`);
            assert.dom('[data-test-cell] [data-test-identity-cell]').exists(`${key} identity cell`);
            assert.dom('[data-test-option] [data-test-select-option]').hasClass('select-option--compact', `${key} select option`);
        });
    }

    test('Order::Pill accepts @order as it always did', async function (assert) {
        this.set('order', { resourceType: 'order', public_id: 'order_1', tracking: 'TRK-1', status: 'created' });

        await render(hbs`<Order::Pill @order={{this.order}} @noPopover={{true}} />`);

        assert.dom('[data-test-resource-pill-title]').hasText('order_1');
        assert.dom('[data-test-resource-pill-subtitle]').hasText('TRK-1');
    });
});
