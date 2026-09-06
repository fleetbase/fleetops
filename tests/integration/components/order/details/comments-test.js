import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import registerTemplateOnly from 'dummy/tests/helpers/register-template-only';

module('Integration | Component | order/details/comments', function (hooks) {
    setupRenderingTest(hooks);

    test('it wraps the order comment thread in a panel', async function (assert) {
        registerTemplateOnly(this.owner, 'comment-thread', hbs`<div data-test-comment-thread={{@subjectType}} data-test-subject={{@subject.id}}></div>`);
        this.set('resource', { id: 'order_1' });

        await render(hbs`<Order::Details::Comments @resource={{this.resource}} />`);

        assert.dom('.panel-title').hasText('Comments');
        assert.dom('[data-test-comment-thread="fleet-ops:order"]').hasAttribute('data-test-subject', 'order_1');
    });
});
