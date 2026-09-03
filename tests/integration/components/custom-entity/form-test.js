import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, fillIn, findAll, render, triggerEvent } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import registerTemplateOnly from 'dummy/tests/helpers/register-template-only';
import createCustomEntity from '@fleetbase/fleetops-engine/utils/create-custom-entity';

module('Integration | Component | custom-entity/form', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        registerTemplateOnly(
            this.owner,
            'unit-input',
            hbs`<button type="button" data-test-unit-change={{@unit}} {{on "click" (fn @onUnitChange (if @measurement "lb" "m"))}}>{{@value}}</button>`
        );
        registerTemplateOnly(this.owner, 'upload-button', hbs`<button type="button" data-test-upload-button disabled={{@disabled}} title={{@helpText}}></button>`);
    });

    test('it renders the entity bound to the inputs and updates the type and units', async function (assert) {
        this.set('resource', createCustomEntity('Pallet', 'pallet', 'A wooden pallet', { photo_url: '/pallet.png', length: 120, width: 80, height: 15, weight: 25 }));

        await render(hbs`<CustomEntity::Form @resource={{this.resource}} />`);

        assert.deepEqual(
            findAll('input').map((input) => input.value),
            ['Pallet', 'A wooden pallet', 'pallet']
        );
        assert.dom('img').hasAttribute('src', '/pallet.png');
        assert.dom('[data-test-upload-button]').isNotDisabled();
        assert.dom('[data-test-upload-button]').hasAttribute('title', '');
        assert.deepEqual(
            findAll('[data-test-unit-change]').map((button) => [button.getAttribute('data-test-unit-change'), button.textContent.trim()]),
            [
                ['cm', '120'],
                ['cm', '80'],
                ['cm', '15'],
                ['kg', '25'],
            ]
        );

        // Characterises DEFECTS #38: the handler dasherizes the typed value, but the two-way Input on
        // the same element writes the raw text back afterwards, so the slug never sticks.
        findAll('input')[2].value = 'Big Box';
        await triggerEvent(findAll('input')[2], 'input');
        assert.strictEqual(this.resource.get('type'), 'Big Box', 'DEFECTS #38: the raw value wins over the dasherized one');

        await click(findAll('[data-test-unit-change]')[1]);
        assert.strictEqual(this.resource.get('dimensions_unit'), 'm');
        await click(findAll('[data-test-unit-change]')[3]);
        assert.strictEqual(this.resource.get('weight_unit'), 'lb');
        assert.deepEqual(
            findAll('[data-test-unit-change]').map((button) => button.getAttribute('data-test-unit-change')),
            ['m', 'm', 'm', 'lb']
        );
    });

    test('the image upload waits for a name and description', async function (assert) {
        this.set('resource', createCustomEntity('Pallet'));

        await render(hbs`<CustomEntity::Form @resource={{this.resource}} />`);

        assert.dom('[data-test-upload-button]').isDisabled();
        assert.dom('[data-test-upload-button]').hasAttribute('title', 'Input custom entity name and description first to upload image');

        await fillIn(findAll('input')[1], 'A wooden pallet');
        assert.dom('[data-test-upload-button]').isNotDisabled();
    });
});
