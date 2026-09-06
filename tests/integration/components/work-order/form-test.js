import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, fillIn, findAll, render } from '@ember/test-helpers';
import { helper } from '@ember/component/helper';
import { tracked } from '@glimmer/tracking';
import { hbs } from 'ember-cli-htmlbars';
import registerTemplateOnly from 'dummy/tests/helpers/register-template-only';
import stubFormInputs from 'dummy/tests/helpers/stub-form-inputs';

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
        stubFormInputs(this.owner);
        // ember-ui's MoneyInput reaches the network through `currentUser`; the completion costs
        // only need something that hands `@onChange` a value.
        registerTemplateOnly(this.owner, 'money-input', hbs`<button type="button" data-test-money-input data-test-value={{@value}} {{on "click" (fn @onChange "125.50")}}></button>`);
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

    test('picking a target type reveals its model select and stores the chosen asset', async function (assert) {
        this.set('resource', new WorkOrderFixture({ status: 'open', priority: 'medium', meta: {} }));

        await render(hbs`<WorkOrder::Form @resource={{this.resource}} />`);
        assert.dom('[data-test-model-select]').doesNotExist('no asset can be picked before a type is');

        await choosePowerSelectOption(3, 'Vehicle');
        assert.dom('[data-test-model-select="vehicle"]').exists('the vehicle select appears');

        await click('[data-test-model-select="vehicle"]');
        assert.strictEqual(this.resource.target.id, 'picked_1', 'the picked vehicle becomes the target');

        // Switching type clears the asset so a stale association is never persisted.
        await choosePowerSelectOption(3, 'Equipment');
        assert.strictEqual(this.resource.target, null);
        assert.dom('[data-test-model-select="equipment"]').exists();
    });

    test('picking an assignee type reveals its model select and stores the chosen assignee', async function (assert) {
        this.set('resource', new WorkOrderFixture({ status: 'open', priority: 'medium', meta: {} }));

        await render(hbs`<WorkOrder::Form @resource={{this.resource}} />`);

        await choosePowerSelectOption(4, 'Vendor');
        await click('[data-test-model-select="vendor"]');
        assert.strictEqual(this.resource.assignee.id, 'picked_1');

        await choosePowerSelectOption(4, 'Contact');
        assert.strictEqual(this.resource.assignee, null, 'switching type clears the assignee');
        assert.dom('[data-test-model-select="contact"]').exists();

        await choosePowerSelectOption(4, 'User');
        assert.dom('[data-test-model-select="user"]').exists();
    });

    test('editing a saved work order restores both polymorphic selectors', async function (assert) {
        class MaintenanceSubjectVehicle {
            static modelName = 'maintenance-subject-vehicle';
        }

        this.set(
            'resource',
            new WorkOrderFixture({
                status: 'open',
                priority: 'medium',
                meta: {},
                target: new MaintenanceSubjectVehicle(),
                // A raw record handed over from vehicle-actions carries its name on the instance.
                assignee: { modelName: 'contact' },
            })
        );

        await render(hbs`<WorkOrder::Form @resource={{this.resource}} />`);

        const triggers = findAll('.ember-power-select-trigger');
        assert.dom(triggers[3]).hasText('Vehicle', 'the target type is restored from the relationship');
        assert.dom(triggers[4]).hasText('Contact', 'and the assignee type from its raw model name');
        assert.dom('[data-test-model-select="vehicle"]').exists();
        assert.dom('[data-test-model-select="contact"]').exists();
    });

    test('a relationship of an unrecognised kind leaves both selectors empty', async function (assert) {
        this.set(
            'resource',
            new WorkOrderFixture({
                status: 'open',
                priority: 'medium',
                meta: {},
                target: { modelName: 'trailer' },
                assignee: { modelName: 'robot' },
            })
        );

        await render(hbs`<WorkOrder::Form @resource={{this.resource}} />`);

        assert.dom('[data-test-model-select]').doesNotExist('neither model name maps to a supported type');
    });

    test('completion details are reported to the caller as they are filled in', async function (assert) {
        const reports = [];
        this.set('onCompletionChange', (completion) => reports.push(completion));
        this.set('resource', new WorkOrderFixture({ status: 'closed', priority: 'medium', meta: {} }));

        await render(hbs`<WorkOrder::Form @resource={{this.resource}} @onCompletionChange={{this.onCompletionChange}} />`);
        assert.dom().containsText('Completion Details');

        const money = findAll('[data-test-money-input]');
        assert.strictEqual(money.length, 3, 'labour, parts and tax');

        await click(money[0]);
        assert.deepEqual(reports.at(-1), { odometer: null, engineHours: null, laborCost: '125.50', partsCost: null, tax: null, notes: null });

        await click(money[1]);
        assert.strictEqual(reports.at(-1).partsCost, '125.50');
        assert.strictEqual(reports.at(-1).laborCost, '125.50', 'the earlier cost is still reported');

        await click(money[2]);
        assert.strictEqual(reports.at(-1).tax, '125.50');
        assert.deepEqual(
            findAll('[data-test-money-input]').map((input) => input.getAttribute('data-test-value')),
            ['125.50', '125.50', '125.50'],
            'each cost is bound back into its own input'
        );

        await fillIn('input[placeholder="e.g. 47250"]', '47250');
        assert.strictEqual(reports.at(-1).odometer, '47250', 'the odometer reaches the caller');

        await fillIn('input[placeholder="e.g. 1420"]', '1420');
        assert.strictEqual(reports.at(-1).engineHours, '1420');

        // The form has a description textarea above the completion panel, so the notes field is
        // picked out by its placeholder.
        await fillIn('textarea[placeholder^="Notes on what was done"]', 'Replaced both front tyres.');
        assert.strictEqual(reports.at(-1).notes, 'Replaced both front tyres.');
    });

    test('completion details are still recorded when nobody is listening', async function (assert) {
        this.set('resource', new WorkOrderFixture({ status: 'closed', priority: 'medium', meta: {} }));

        await render(hbs`<WorkOrder::Form @resource={{this.resource}} />`);

        await click(findAll('[data-test-money-input]')[0]);
        assert.dom().containsText('Completion Details', 'the form survives having no completion listener');
    });
});
