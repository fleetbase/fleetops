import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { findAll, render, settled } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import stubFormInputs from 'dummy/tests/helpers/stub-form-inputs';

function field(name) {
    const label = findAll('.field-name').find((el) => el.textContent.trim() === name);
    return label ? label.nextElementSibling.textContent.replace(/\s+/g, ' ').trim() : null;
}

module('Integration | Component | sensor/details', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        stubFormInputs(this.owner);
    });

    test('it renders identity, thresholds, readings, status and associations', async function (assert) {
        this.set('resource', {
            name: 'Cabin temp',
            type: 'temperature',
            unit: '°C',
            internal_id: 'INT-9',
            serial_number: 'SN-9',
            min_threshold: -5,
            max_threshold: 8,
            threshold_inclusive: true,
            threshold_status: 'normal',
            last_reading_at: new Date(2026, 8, 1, 12, 0),
            report_frequency_sec: 60,
            status: 'active',
            is_active: true,
            device: { name: 'Tracker A' },
            warranty: { name: 'Standard' },
        });

        await render(hbs`<Sensor::Details @resource={{this.resource}} class="probe" />`);

        assert.dom('.details-wrapper').hasClass('probe');
        assert.deepEqual(
            findAll('.panel-title').map((el) => el.textContent.trim()),
            ['Identity', 'Thresholds', 'Readings', 'Status', 'Integration & Associations']
        );
        assert.strictEqual(field('Name'), 'Cabin temp');
        assert.strictEqual(field('Sensor Type'), 'Temperature Sensor');
        assert.strictEqual(field('Unit'), '°C');
        assert.strictEqual(field('Internal ID'), 'INT-9');
        assert.strictEqual(field('Serial Number'), 'SN-9');
        assert.strictEqual(field('Minimum Threshold'), '-5');
        assert.strictEqual(field('Maximum Threshold'), '8');
        assert.strictEqual(field('Threshold Inclusive'), 'Yes');
        assert.strictEqual(field('Threshold Status'), 'Normal');
        assert.ok(/2026/.test(field('Last Reading At')), 'the reading date is formatted');
        assert.strictEqual(field('Report Frequency'), '60 seconds');
        assert.strictEqual(field('Active Status'), 'Active');
        assert.strictEqual(field('Device'), 'Tracker A');
        assert.strictEqual(field('Warranty'), 'Standard');
        assert.dom('[data-test-custom-fields]').exists();

        for (const [status, label] of [
            ['out_of_range', 'Out of Range'],
            ['above_maximum', 'Above Maximum'],
            ['below_minimum', 'Below Minimum'],
            ['unknown_state', 'Unknown State'],
        ]) {
            this.set('resource', { ...this.resource, threshold_status: status });
            await settled();
            assert.strictEqual(field('Threshold Status'), label);
        }

        this.set('resource', { ...this.resource, threshold_inclusive: false, report_frequency_sec: null, is_active: false, type: 'bogus', device: null, warranty: null });
        await settled();
        assert.strictEqual(field('Threshold Inclusive'), 'No');
        assert.strictEqual(field('Report Frequency'), '-');
        assert.strictEqual(field('Active Status'), 'Inactive');
        assert.strictEqual(field('Sensor Type'), '-');
        assert.strictEqual(field('Device'), '-');
        assert.strictEqual(field('Warranty'), '-');
    });
});
