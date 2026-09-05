import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { findAll, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import registerTemplateOnly from 'dummy/tests/helpers/register-template-only';

module('Integration | Component | fuel-report/details', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        registerTemplateOnly(this.owner, 'custom-field/yield', hbs`<div data-test-custom-fields></div>`);
    });

    test('it renders the report fields, source and provider', async function (assert) {
        this.set('resource', {
            public_id: 'fuel_report_1',
            status: 'approved',
            source: 'telematics',
            provider: 'wex',
            reporter_name: 'Ron Reporter',
            driver_name: 'Ada Driver',
            vehicle_name: 'Van 12',
            odometer: 120345,
            amount: 4550,
            currency: 'USD',
            volume: 3,
            metric_unit: 'gallon',
            createdAt: '2026-01-02',
            location: { type: 'Point', coordinates: [103.8, 1.3] },
        });

        await render(hbs`<FuelReport::Details @resource={{this.resource}} />`);

        assert.dom('.click-to-copy--value').hasText('fuel_report_1');
        assert.dom(this.element).includesText('Ron Reporter').includesText('Ada Driver').includesText('Van 12').includesText('120345');
        assert.dom(this.element).includesText('3 gallons').includesText('$45.50', 'the amount is stored in cents').includesText('2026-01-02');
        assert.dom(this.element).includesText('Source').includesText('Provider').includesText('wex');
        assert.strictEqual(findAll('.status-badge').length, 2, 'status and source badges');
        assert.dom('[data-test-custom-fields]').exists('custom fields render below the details');
    });

    test('it omits source and provider when the report has none, and dashes empty fields', async function (assert) {
        this.set('resource', { public_id: 'fuel_report_2', status: 'pending' });

        await render(hbs`<FuelReport::Details @resource={{this.resource}} />`);

        assert.dom(this.element).doesNotIncludeText('Source').doesNotIncludeText('Provider');
        assert.strictEqual(findAll('.status-badge').length, 1);
        assert.dom(this.element).includesText('0 -', 'a missing volume pluralizes the empty unit');
        assert.strictEqual(findAll('.field-value').filter((element) => element.textContent.trim() === '-').length, 4, 'reporter, driver, vehicle and odometer are dashed');
    });
});
