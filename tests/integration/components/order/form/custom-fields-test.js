import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, findAll, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import registerTemplateOnly from 'dummy/tests/helpers/register-template-only';

module('Integration | Component | order/form/custom-fields', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        registerTemplateOnly(
            this.owner,
            'custom-field/input',
            hbs`<button type="button" data-test-custom-field={{@customField.id}} data-test-subject={{@subject.id}} {{on "click" (fn @onChange @customField "typed")}}>{{@customField.label}}</button>`
        );
    });

    test('it renders a panel per custom field group only when the order has a config', async function (assert) {
        const changes = [];
        this.set('resource', { order_config: { id: 'config_1' } });
        this.set('customFields', {
            customFieldGroups: [
                { name: 'Handling', meta: { grid_size: 3 }, customFields: [{ id: 'cf_1', label: 'Fragile' }] },
                {
                    name: 'Billing',
                    meta: {},
                    customFields: [
                        { id: 'cf_2', label: 'PO number' },
                        { id: 'cf_3', label: 'Cost centre' },
                    ],
                },
            ],
            setFieldValue: (customField, value) => changes.push([customField.id, value]),
        });

        await render(hbs`<Order::Form::CustomFields @resource={{this.resource}} @customFields={{this.customFields}} />`);

        assert.deepEqual(
            findAll('.panel-title').map((el) => el.textContent.trim()),
            ['Handling', 'Billing']
        );
        const grids = findAll('.grid');
        assert.dom(grids[0]).hasClass('lg:grid-cols-3');
        assert.dom(grids[1]).hasClass('lg:grid-cols-2', 'grid size defaults to two columns');
        assert.dom('[data-test-custom-field]').exists({ count: 3 });
        assert.dom('[data-test-custom-field="cf_2"]').hasAttribute('data-test-subject', 'config_1');

        await click('[data-test-custom-field="cf_3"]');
        assert.deepEqual(changes, [['cf_3', 'typed']]);

        this.set('resource', { order_config: null });
        assert.dom('.panel-title').doesNotExist();
    });
});
