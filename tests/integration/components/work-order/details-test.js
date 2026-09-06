import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { findAll, render, settled } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import stubFormInputs from 'dummy/tests/helpers/stub-form-inputs';

function field(name) {
    const label = findAll('.field-name').find((el) => el.textContent.trim() === name);
    if (!label) return null;
    const value = label.nextElementSibling;
    const copyable = value.querySelector('.click-to-copy--value');
    return (copyable ?? value).textContent.replace(/\s+/g, ' ').trim();
}

function panelTitles() {
    return findAll('.panel-title').map((el) => el.textContent.trim());
}

module('Integration | Component | work-order/details', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        stubFormInputs(this.owner);
    });

    test('it renders the overview, budget, instructions and the completion breakdown once closed', async function (assert) {
        this.set('resource', {
            code: 'WO-1',
            public_id: 'work_order_1',
            subject: 'Replace brake pads',
            category: 'preventive_maintenance',
            status: 'open',
            priority: 'critical',
            target: { displayName: 'Truck 1' },
            assignee: { name: 'Parts Co' },
            schedule: { name: 'Quarterly' },
            opened_at: new Date(2026, 8, 1, 8, 0),
            due_at: new Date(2026, 8, 5, 17, 0),
            closed_at: null,
            estimated_cost: 10000,
            approved_budget: 12000,
            actual_cost: 9500,
            cost_center: 'Fleet North',
            budget_code: 'BC-9',
            currency: 'USD',
            instructions: 'Check rotors too',
            meta: {},
        });

        await render(hbs`<WorkOrder::Details @resource={{this.resource}} class="probe" />`);

        assert.dom('.next-content-panel-wrapper').hasClass('probe');
        assert.deepEqual(panelTitles(), ['Overview', 'Budget', 'Instructions']);
        assert.strictEqual(field('Code'), 'WO-1');
        assert.strictEqual(field('ID'), 'work_order_1');
        assert.strictEqual(field('Subject'), 'Replace brake pads');
        assert.strictEqual(field('Category'), 'Preventive Maintenance');
        assert.strictEqual(field('Status'), 'Open');
        assert.strictEqual(field('Priority'), 'Critical');
        assert.strictEqual(field('Target Asset'), 'Truck 1');
        assert.strictEqual(field('Assigned Vendor'), 'Parts Co');
        assert.strictEqual(field('Linked Schedule'), 'Quarterly');
        assert.strictEqual(field('Opened At'), '01 Sep 2026, 08:00');
        assert.strictEqual(field('Due At'), '05 Sep 2026, 17:00');
        assert.strictEqual(field('Closed At'), '-');
        assert.strictEqual(field('Estimated Cost'), '$100.00');
        assert.strictEqual(field('Approved Budget'), '$120.00');
        assert.strictEqual(field('Actual Cost'), '$95.00');
        assert.strictEqual(field('Cost Centre'), 'Fleet North');
        assert.strictEqual(field('Budget Code'), 'BC-9');
        assert.strictEqual(field('Currency'), 'USD');
        assert.dom().doesNotIncludeText('Check rotors too', 'instructions start collapsed');
        assert.dom('[data-test-custom-fields]').exists();

        for (const [priority, label] of [
            ['high', 'High'],
            ['medium', 'Medium'],
            ['low', 'Low'],
        ]) {
            this.set('resource', { ...this.resource, priority });
            await settled();
            assert.strictEqual(field('Priority'), label);
        }

        this.set('resource', {
            ...this.resource,
            status: 'closed',
            closed_at: new Date(2026, 8, 6, 12, 0),
            schedule: null,
            cost_center: null,
            budget_code: null,
            instructions: null,
            target: null,
            target_name: 'Van 2',
            assignee: null,
            assignee_name: 'Vendor B',
            meta: { completion_data: { odometer: 120500, engine_hours: 410, labor_cost: 5000, parts_cost: 3000, tax: 800, total_cost: 8800, currency: 'USD', notes: 'All good' } },
        });
        await settled();

        assert.deepEqual(panelTitles(), ['Overview', 'Budget', 'Completion & Cost Breakdown']);
        assert.strictEqual(field('Linked Schedule'), null);
        assert.strictEqual(field('Cost Centre'), null);
        assert.strictEqual(field('Budget Code'), null);
        assert.strictEqual(field('Target Asset'), 'Van 2');
        assert.strictEqual(field('Assigned Vendor'), 'Vendor B');
        assert.strictEqual(field('Closed At'), '06 Sep 2026, 12:00');
        assert.strictEqual(field('Odometer'), '120500 km');
        assert.strictEqual(field('Engine Hours'), '410');
        assert.strictEqual(field('Labour Cost'), '$50.00');
        assert.strictEqual(field('Parts Cost'), '$30.00');
        assert.strictEqual(field('Tax'), '$8.00');
        assert.strictEqual(field('Total Cost'), '$88.00');
        assert.strictEqual(field('Completion Notes'), 'All good');

        this.set('resource', { ...this.resource, meta: { completion_data: { currency: 'USD' } } });
        await settled();
        assert.strictEqual(field('Odometer'), '—');
        assert.strictEqual(field('Engine Hours'), '-');
        assert.strictEqual(field('Completion Notes'), null);
    });
});
