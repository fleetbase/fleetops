import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, findAll, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import registerTemplateOnly from 'dummy/tests/helpers/register-template-only';
import { AbilitiesStub } from 'dummy/tests/helpers/stub-form-inputs';

const PANELS = ['detail', 'custom-fields', 'purchase-rate', 'tracking', 'notes', 'integrated-vendor-details', 'route', 'payload', 'documents', 'comments', 'metadata'];

module('Integration | Component | order/details', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:abilities', AbilitiesStub);
        registerTemplateOnly(this.owner, 'registry-yield', hbs`<div data-test-registry={{@registry}}></div>`);
        for (const panel of PANELS) {
            registerTemplateOnly(this.owner, `order/details/${panel}`, hbs`<div data-test-panel={{@resource.public_id}}></div>`);
        }
        registerTemplateOnly(this.owner, 'order/details/proof', hbs`<div data-test-proof={{@reloadToken}}></div>`);
        registerTemplateOnly(
            this.owner,
            'order/details/activity',
            hbs`<div data-test-activity><button type="button" data-test-created {{on "click" (fn @onChange (hash activityCreated="act_1" proofCreated=true))}}></button><button type="button" data-test-plain {{on "click" (fn @onChange "act_2")}}></button></div>`
        );
        this.set('resource', { public_id: 'order_1', status: 'created' });
    });

    test('the default layout mounts every panel and relays activity changes with a proof reload token', async function (assert) {
        const changes = [];
        this.set('onActivityChanged', (activity) => changes.push(activity));

        await render(hbs`<Order::Details @resource={{this.resource}} @onActivityChanged={{this.onActivityChanged}} @proofReloadToken={{7}} />`);

        assert.strictEqual(findAll('[data-test-panel="order_1"]').length, PANELS.length);
        assert.deepEqual(
            findAll('[data-test-registry]').map((el) => el.getAttribute('data-test-registry')),
            ['fleet-ops:component:order:details:start', 'fleet-ops:component:order:details:after-details', 'fleet-ops:component:order:details', 'fleet-ops:component:order:details:end']
        );
        assert.dom('[data-test-proof]').hasAttribute('data-test-proof', '7:0');

        await click('[data-test-created]');
        assert.deepEqual(changes, ['act_1'], 'the created activity is unwrapped');
        assert.dom('[data-test-proof]').hasAttribute('data-test-proof', '7:1', 'a created proof bumps the reload token');

        await click('[data-test-plain]');
        assert.deepEqual(changes, ['act_1', 'act_2'], 'a bare activity passes through');
        assert.dom('[data-test-proof]').hasAttribute('data-test-proof', '7:1');
    });

    test('without a listener or an outer token the changes are still safe and the block form yields the panels', async function (assert) {
        await render(hbs`<Order::Details @resource={{this.resource}} />`);
        assert.dom('[data-test-proof]').hasAttribute('data-test-proof', '0:0');
        await click('[data-test-created]');
        assert.dom('[data-test-proof]').hasAttribute('data-test-proof', '0:1');

        await render(hbs`<Order::Details @resource={{this.resource}} as |Panels|><Panels.Proof /><Panels.Activity /><Panels.RegistryYieldEnd /></Order::Details>`);
        assert.dom('[data-test-proof]').hasAttribute('data-test-proof', '0:0');
        assert.dom('[data-test-panel]').doesNotExist('only the yielded panels render');
        assert.dom('[data-test-registry="fleet-ops:component:order:details:end"]').exists();
        await click('[data-test-created]');
        assert.dom('[data-test-proof]').hasAttribute('data-test-proof', '0:1');
    });
});
