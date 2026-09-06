import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { findAll, render, settled } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import stubFormInputs from 'dummy/tests/helpers/stub-form-inputs';

function field(name) {
    const label = findAll('.field-name').find((el) => el.textContent.trim() === name);
    return label ? label.nextElementSibling.textContent.replace(/\s+/g, ' ').trim() : null;
}

module('Integration | Component | maintenance/details', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        stubFormInputs(this.owner);
    });

    test('it renders the overview, priorities, line items and totals', async function (assert) {
        this.set('resource', {
            public_id: 'maintenance_1',
            type: 'oil_change',
            status: 'scheduled',
            priority: 'critical',
            maintainable: { displayName: 'Truck 1' },
            performed_by: { name: 'Sam Mechanic' },
            work_order_uuid: 'wo_1',
            work_order_subject: 'Quarterly service',
            scheduled_at: new Date(2026, 8, 10, 9, 30),
            started_at: null,
            completed_at: null,
            is_overdue: true,
            odometer: 120000,
            engine_hours: 400,
            duration_hours: 2.5,
            summary: 'Replace oil and filter',
            notes: 'Use synthetic',
            line_items: [
                { description: 'Oil filter', quantity: 2, unit_cost: 1500, currency: 'USD' },
                { description: 'Labour', quantity: 1, unit_cost: 5000 },
            ],
            labor_cost: 5000,
            parts_cost: 3000,
            tax: 800,
            total_cost: 8800,
            currency: 'USD',
        });

        await render(hbs`<Maintenance::Details @resource={{this.resource}} class="probe" />`);

        assert.dom('.next-content-panel-wrapper').hasClass('probe');
        assert.strictEqual(field('ID'), 'maintenance_1');
        assert.strictEqual(field('Type'), 'Oil Change');
        assert.strictEqual(field('Status'), 'Scheduled');
        assert.strictEqual(field('Priority'), 'Critical');
        assert.strictEqual(field('Maintainable Asset'), 'Truck 1');
        assert.strictEqual(field('Performed By'), 'Sam Mechanic');
        assert.strictEqual(field('Linked Work Order'), 'Quarterly service');
        assert.strictEqual(field('Scheduled At'), '10 Sep 2026, 09:30');
        assert.strictEqual(field('Started At'), '-');
        assert.strictEqual(field('Overdue'), 'Overdue');
        assert.strictEqual(field('Days Until Due'), null);
        assert.strictEqual(field('Odometer'), '120000');
        assert.strictEqual(field('Engine Hours'), '400');
        assert.strictEqual(field('Duration'), '2.5 hrs');
        assert.dom().includesText('Replace oil and filter');
        assert.dom().includesText('Use synthetic');

        const rows = findAll('tbody tr').map((row) => [...row.querySelectorAll('td')].map((td) => td.textContent.trim()));
        assert.deepEqual(rows, [
            ['Oil filter', '2', '$15.00', '$30.00'],
            ['Labour', '1', '$50.00', '$50.00'],
        ]);
        assert.dom().includesText('$88.00');
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
            is_overdue: false,
            days_until_due: 3,
            line_items: [],
            work_order_uuid: null,
            work_order_subject: null,
            summary: null,
            notes: null,
            maintainable: null,
            maintainable_name: 'Van 2',
            performed_by: null,
        });
        await settled();

        assert.strictEqual(field('Overdue'), null);
        assert.strictEqual(field('Days Until Due'), '3 days');
        assert.strictEqual(field('Linked Work Order'), null);
        assert.strictEqual(field('Maintainable Asset'), 'Van 2');
        assert.strictEqual(field('Performed By'), '-');
        assert.dom('tbody').doesNotExist();
        assert.dom().includesText('No line items recorded.');
    });
});
