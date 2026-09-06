import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import registerTemplateOnly from 'dummy/tests/helpers/register-template-only';

module('Integration | Component | order/form/metadata', function (hooks) {
    setupRenderingTest(hooks);

    test('it edits the order metadata inside a panel', async function (assert) {
        registerTemplateOnly(
            this.owner,
            'metadata-editor',
            hbs`<button type="button" data-test-metadata-editor={{@value.priority}} {{on "click" (fn @onChange (hash priority="high"))}}></button>`
        );
        this.set('resource', { meta: { priority: 'low' } });

        await render(hbs`<Order::Form::Metadata @resource={{this.resource}} />`);

        assert.dom('.panel-title').hasText('Metadata');
        assert.dom('[data-test-metadata-editor="low"]').exists();

        await click('[data-test-metadata-editor]');
        assert.deepEqual(this.resource.meta, { priority: 'high' });
        assert.dom('[data-test-metadata-editor="high"]').exists();
    });
});
