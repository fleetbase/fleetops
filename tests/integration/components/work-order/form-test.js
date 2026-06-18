import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, findAll, render } from '@ember/test-helpers';
import { helper } from '@ember/component/helper';
import { hbs } from 'ember-cli-htmlbars';

async function choosePowerSelectOption(index, text) {
    await click(findAll('.ember-power-select-trigger')[index]);

    const option = findAll('.ember-power-select-option').find((element) => element.textContent.includes(text));
    await click(option);
}

module('Integration | Component | work-order/form', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register(
            'helper:cannot-write',
            helper(() => false)
        );
    });

    test('it renders lifecycle status and category option labels', async function (assert) {
        this.set('resource', {
            code: null,
            subject: 'Oil service',
            category: 'preventive_maintenance',
            status: 'open',
            priority: 'medium',
            meta: {},
        });

        await render(hbs`<WorkOrder::Form @resource={{this.resource}} />`);

        assert.dom('.work-order-form').exists();
        assert.dom().containsText('Preventive Maintenance (PM)');
        assert.dom().containsText('Open');
    });

    test('selecting a lifecycle status stores the status value', async function (assert) {
        this.set('resource', {
            status: 'open',
            priority: 'medium',
            meta: {},
        });

        await render(hbs`<WorkOrder::Form @resource={{this.resource}} />`);
        await choosePowerSelectOption(1, 'Quality Check');

        assert.strictEqual(this.resource.status, 'quality_check');
    });

    test('selecting a category preserves existing metadata', async function (assert) {
        this.set('resource', {
            status: 'open',
            priority: 'medium',
            meta: {
                existing_key: 'keep-me',
            },
        });

        await render(hbs`<WorkOrder::Form @resource={{this.resource}} />`);
        await choosePowerSelectOption(0, 'Tire Issue');

        assert.strictEqual(this.resource.meta.existing_key, 'keep-me');
        assert.strictEqual(this.resource.category, 'tire_issue');
    });

    test('closed status still reveals completion details', async function (assert) {
        this.set('resource', {
            status: 'open',
            priority: 'medium',
            meta: {},
        });

        await render(hbs`<WorkOrder::Form @resource={{this.resource}} />`);

        assert.dom().doesNotContainText('Completion Details');

        await choosePowerSelectOption(1, 'Closed');

        assert.strictEqual(this.resource.status, 'closed');
        assert.dom().containsText('Completion Details');
    });
});
