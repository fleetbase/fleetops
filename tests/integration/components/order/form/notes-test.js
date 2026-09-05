import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { fillIn, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import { AbilitiesStub, makeRecord } from 'dummy/tests/helpers/stub-form-inputs';

module('Integration | Component | order/form/notes', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        this.abilities = AbilitiesStub.create();
        this.owner.register('service:abilities', this.abilities, { instantiate: false });
    });

    test('it edits the order notes and honours the row count and write permission', async function (assert) {
        this.set('resource', makeRecord('order', { notes: '' }, { isNew: false }));

        await render(hbs`<Order::Form::Notes @resource={{this.resource}} />`);

        assert.dom('.panel-title').hasText('Notes');
        assert.dom('textarea').hasAttribute('placeholder', 'Enter order notes here....');
        assert.dom('textarea').hasAttribute('rows', '4');
        assert.dom('textarea').isNotDisabled();

        await fillIn('textarea', 'Leave at the side door.');
        assert.strictEqual(this.resource.notes, 'Leave at the side door.');

        this.abilities.allow = false;
        await render(hbs`<Order::Form::Notes @resource={{this.resource}} @rows={{8}} />`);
        assert.dom('textarea').hasAttribute('rows', '8');
        assert.dom('textarea').isDisabled('a record the user cannot update is read-only');
    });
});
