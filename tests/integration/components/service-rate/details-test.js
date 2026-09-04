import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { findAll, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import registerTemplateOnly from 'dummy/tests/helpers/register-template-only';

function field(name) {
    const label = findAll('.field-name').find((el) => el.textContent.trim() === name);
    return label ? label.nextElementSibling.textContent.replace(/\s+/g, ' ').trim() : null;
}

module('Integration | Component | service-rate/details', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        registerTemplateOnly(this.owner, 'custom-field/yield', hbs`<div data-test-custom-fields></div>`);
    });

    test('a fixed-rate service lists its distance fees, restrictions and additional fees', async function (assert) {
        this.set('resource', {
            service_name: 'Standard Delivery',
            order_config: { name: 'Transport' },
            base_fee: 500,
            currency: 'USD',
            rate_calculation_method: 'fixed_meter',
            isFixedRate: true,
            duration_terms: '2-3 days',
            max_distance: 50,
            max_distance_unit: 'km',
            rateFees: [
                { distance: 0, fee: 1000 },
                { distance: 10, fee: 1500 },
            ],
            serviceArea: { name: 'Texas' },
            zone: { name: 'Austin Metro' },
            cod_calculation_method: 'flat',
            hasCodFlatFee: true,
            cod_flat_fee: 250,
            peak_hours_calculation_method: 'percentage',
            hasPeakHoursPercentageFee: true,
            peak_hours_percent: 15,
        });

        await render(hbs`<ServiceRate::Details @resource={{this.resource}} />`);

        assert.strictEqual(field('Service Name'), 'Standard Delivery');
        assert.strictEqual(field('Base Fee'), '$5.00');
        assert.dom().includesText('Fixed Rate');
        assert.dom().includesText('Transport');
        assert.dom().includesText('2-3 days');
        assert.dom().doesNotIncludeText('Estimated Delivery Days');
        assert.strictEqual(field('Max Distance Unit'), 'Kilometer');
        assert.deepEqual(
            findAll('tbody tr').map((row) => row.textContent.replace(/\s+/g, ' ').trim()),
            ['0-1 km $10.00', '10-11 km $15.00']
        );
        assert.dom().includesText('Texas');
        assert.dom().includesText('Austin Metro');
        assert.dom().includesText('Flat Fee');
        assert.dom().includesText('$2.50');
        assert.dom().includesText('15% surcharge');
        assert.dom('[data-test-custom-fields]').exists();
    });

    test('per-drop, multi-zone, per-meter and algorithm rates render their own panels', async function (assert) {
        this.set('resource', { currency: 'USD', rate_calculation_method: 'per_drop', isPerDrop: true, rateFees: [{ min: 1, max: 3, fee: 250 }] });
        await render(hbs`<ServiceRate::Details @resource={{this.resource}} />`);
        assert.dom().includesText('Per Drop-off');
        assert.deepEqual(
            findAll('tbody tr').map((row) => row.textContent.replace(/\s+/g, ' ').trim()),
            ['1 3 $2.50']
        );
        assert.dom().includesText('Not configured');

        this.set('resource', { currency: 'USD', rate_calculation_method: 'per_drop', isPerDrop: true, rateFees: [] });
        await render(hbs`<ServiceRate::Details @resource={{this.resource}} />`);
        assert.dom().includesText('No per drop fees defined');

        this.set('resource', {
            currency: 'USD',
            rate_calculation_method: 'multi_zone_distance',
            isMultiZoneDistance: true,
            rateFees: [
                { label: 'Downtown', geography_type: 'zone', zone: { name: 'CBD' }, priority: 1, fee: 300, distance_unit: 'km' },
                { label: 'Anywhere', geography_type: 'fallback', priority: 9, fee: 500, distance_unit: 'km' },
                { label: 'Region', geography_type: 'service_area', service_area: { name: 'North' }, priority: 2, fee: 400, distance_unit: 'mi' },
            ],
        });
        await render(hbs`<ServiceRate::Details @resource={{this.resource}} />`);
        assert.dom().includesText('Multi-zone Distance');
        assert.deepEqual(
            findAll('tbody tr').map((row) => row.textContent.replace(/\s+/g, ' ').trim()),
            ['Downtown Zone CBD 1 $3.00 km', 'Anywhere Fallback Unmatched route distance 9 $5.00 km', 'Region Service Area North 2 $4.00 mi']
        );

        this.set('resource', { currency: 'USD', rate_calculation_method: 'multi_zone_distance', isMultiZoneDistance: true, rateFees: [] });
        await render(hbs`<ServiceRate::Details @resource={{this.resource}} />`);
        assert.dom().includesText('No geographic pricing rules defined');

        this.set('resource', { currency: 'USD', rate_calculation_method: 'per_meter', isPerMeter: true, per_meter_flat_rate_fee: 200, per_meter_unit: 'mi', base_fee: 100 });
        await render(hbs`<ServiceRate::Details @resource={{this.resource}} />`);
        assert.dom().includesText('Per Meter');
        assert.strictEqual(field('Distance Unit'), 'Mile');
        assert.dom('code').includesText('(200 * {distance} mi) + 100');

        this.set('resource', { currency: 'USD', rate_calculation_method: 'per_meter', isPerMeter: true, per_meter_flat_rate_fee: 200, per_meter_unit: 'furlong' });
        await render(hbs`<ServiceRate::Details @resource={{this.resource}} />`);
        assert.strictEqual(field('Distance Unit'), 'furlong', 'an unknown unit falls back to its code');
        assert.dom('code').includesText('+ $0.00');

        this.set('resource', { currency: 'USD', rate_calculation_method: 'algo', isAlgorithm: true, algorithm: 'return distance * 2;' });
        await render(hbs`<ServiceRate::Details @resource={{this.resource}} />`);
        assert.dom().includesText('Algorithm');
        assert.dom('pre code').hasText('return distance * 2;');
    });

    test('a parcel service shows estimated days and its parcel fees, and unknown methods fall back', async function (assert) {
        this.set('resource', {
            currency: 'USD',
            rate_calculation_method: 'parcel',
            isParcelService: true,
            estimated_days: 3,
            parcelFees: [{ size: 'small', length: 10, width: 20, height: 5, weight: 2, dimensions_unit: 'cm', weight_unit: 'kg', fee: 150 }],
            cod_calculation_method: 'percentage',
            hasCodPercentageFee: true,
            cod_percent: 5,
            peak_hours_calculation_method: 'flat',
            hasPeakHoursFlatFee: true,
            peak_hours_flat_fee: 300,
            max_distance_unit: 'league',
        });

        await render(hbs`<ServiceRate::Details @resource={{this.resource}} />`);

        assert.dom().includesText('Parcel Rate');
        assert.strictEqual(field('Estimated Delivery Days'), '3');
        assert.dom().includesText('Small');
        assert.dom().includesText('10 cm');
        assert.dom().includesText('2 kg');
        assert.dom().includesText('Centimeter');
        assert.dom().includesText('Kilogram');
        assert.dom().includesText('$1.50');
        assert.dom().includesText('5% of order value');
        assert.dom().includesText('$3.00');
        assert.dom('img[alt="parcel size small"]').exists();

        this.set('resource', {
            currency: 'USD',
            rate_calculation_method: 'mystery',
            isParcelService: true,
            parcelFees: [],
            cod_calculation_method: 'mystery',
            peak_hours_calculation_method: 'mystery',
        });
        await render(hbs`<ServiceRate::Details @resource={{this.resource}} />`);
        assert.dom().includesText('mystery', 'an unknown calculation method renders its code');
        assert.dom().includesText('No parcel fees configured');
        assert.dom().doesNotIncludeText('Not configured');
        assert.dom('.badge, [class*="badge"]').doesNotExist('unknown fee methods render no badge');
    });
});
