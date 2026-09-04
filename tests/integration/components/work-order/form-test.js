import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, findAll, render } from '@ember/test-helpers';
import { helper } from '@ember/component/helper';
import { tracked } from '@glimmer/tracking';
import { hbs } from 'ember-cli-htmlbars';
import registerTemplateOnly from 'dummy/tests/helpers/register-template-only';

/**
 * The form mutates the record through ember-ui's `set-model-attr` helper, which calls
 * `model.set(attr, value)`; the status it writes also re-renders the completion panel, so the
 * mutated fields have to be tracked.
 */
class WorkOrderFixture {
    static modelName = 'work-order';
    isNew = false;
    @tracked category;
    @tracked status;
    @tracked priority;
    @tracked meta;

    constructor(attributes = {}) {
        Object.assign(this, attributes);
    }

    set(key, value) {
        this[key] = value;
        return value;
    }
}

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
        registerTemplateOnly(this.owner, 'custom-field/yield', hbs`<div data-test-custom-fields></div>`);
    });

    test('it renders lifecycle status and category option labels', async function (assert) {
        this.set(
            'resource',
            new WorkOrderFixture({
                code: null,
                subject: 'Oil service',
                category: 'preventive_maintenance',
                status: 'open',
                priority: 'medium',
                meta: {},
            })
        );

        await render(hbs`<WorkOrder::Form @resource={{this.resource}} />`);

        assert.dom('.work-order-form').exists();
        assert.dom().containsText('Preventive Maintenance (PM)');
        assert.dom().containsText('Open');
    });

    test('selecting a lifecycle status stores the status value', async function (assert) {
        this.set(
            'resource',
            new WorkOrderFixture({
                status: 'open',
                priority: 'medium',
                meta: {},
            })
        );

        await render(hbs`<WorkOrder::Form @resource={{this.resource}} />`);
        await choosePowerSelectOption(1, 'Quality Check');

        assert.strictEqual(this.resource.status, 'quality_check');
    });

    test('selecting a category preserves existing metadata', async function (assert) {
        this.set(
            'resource',
            new WorkOrderFixture({
                status: 'open',
                priority: 'medium',
                meta: {
                    existing_key: 'keep-me',
                },
            })
        );

        await render(hbs`<WorkOrder::Form @resource={{this.resource}} />`);
        await choosePowerSelectOption(0, 'Tire Issue');

        assert.strictEqual(this.resource.meta.existing_key, 'keep-me');
        assert.strictEqual(this.resource.category, 'tire_issue');
    });

    test('closed status still reveals completion details', async function (assert) {
        this.set(
            'resource',
            new WorkOrderFixture({
                status: 'open',
                priority: 'medium',
                meta: {},
            })
        );

        await render(hbs`<WorkOrder::Form @resource={{this.resource}} />`);

        assert.dom().doesNotContainText('Completion Details');

        await choosePowerSelectOption(1, 'Closed');

        assert.strictEqual(this.resource.status, 'closed');
        assert.dom().containsText('Completion Details');
    });
});
