import { module, test } from 'qunit';
import ServiceRateFormComponent from 'dummy/components/service-rate/form';

function makeRule(initial = {}) {
    return {
        ...initial,
        set(key, value) {
            this[key] = value;
        },
    };
}

module('Unit | Component | service-rate/form', function () {
    test('selecting zone keeps the geography type before a zone is selected', function (assert) {
        const rule = makeRule({
            zone: { id: 'zone_1' },
            zone_uuid: 'zone_1',
            service_area: { id: 'service_area_1' },
            service_area_uuid: 'service_area_1',
            is_fallback: true,
        });

        ServiceRateFormComponent.prototype.selectRuleGeographyType(rule, { value: 'zone' });

        assert.strictEqual(rule.selected_geography_type, 'zone');
        assert.strictEqual(rule.zone, null);
        assert.strictEqual(rule.zone_uuid, null);
        assert.strictEqual(rule.service_area, null);
        assert.strictEqual(rule.service_area_uuid, null);
        assert.false(rule.is_fallback);
    });

    test('selecting service area keeps the geography type and clears incompatible values', function (assert) {
        const rule = makeRule({
            zone: { id: 'zone_1' },
            zone_uuid: 'zone_1',
            is_fallback: true,
        });

        ServiceRateFormComponent.prototype.selectRuleGeographyType(rule, { value: 'service_area' });

        assert.strictEqual(rule.selected_geography_type, 'service_area');
        assert.strictEqual(rule.zone, null);
        assert.strictEqual(rule.zone_uuid, null);
        assert.strictEqual(rule.service_area, null);
        assert.strictEqual(rule.service_area_uuid, null);
        assert.false(rule.is_fallback);
    });

    test('selecting fallback keeps fallback mode and clears geography values', function (assert) {
        const rule = makeRule({
            zone: { id: 'zone_1' },
            zone_uuid: 'zone_1',
            service_area: { id: 'service_area_1' },
            service_area_uuid: 'service_area_1',
            is_fallback: false,
        });

        ServiceRateFormComponent.prototype.selectRuleGeographyType(rule, { value: 'fallback' });

        assert.strictEqual(rule.selected_geography_type, 'fallback');
        assert.strictEqual(rule.zone, null);
        assert.strictEqual(rule.zone_uuid, null);
        assert.strictEqual(rule.service_area, null);
        assert.strictEqual(rule.service_area_uuid, null);
        assert.true(rule.is_fallback);
    });

    test('selecting a zone sets zone geography and clears service area values', function (assert) {
        const zone = { id: 'zone_1' };
        const rule = makeRule({
            service_area: { id: 'service_area_1' },
            service_area_uuid: 'service_area_1',
            is_fallback: true,
        });

        ServiceRateFormComponent.prototype.selectRuleZone(rule, zone);

        assert.strictEqual(rule.selected_geography_type, 'zone');
        assert.strictEqual(rule.zone, zone);
        assert.strictEqual(rule.zone_uuid, 'zone_1');
        assert.strictEqual(rule.service_area, null);
        assert.strictEqual(rule.service_area_uuid, null);
        assert.false(rule.is_fallback);
    });

    test('selecting a service area sets service area geography and clears zone values', function (assert) {
        const serviceArea = { id: 'service_area_1' };
        const rule = makeRule({
            zone: { id: 'zone_1' },
            zone_uuid: 'zone_1',
            is_fallback: true,
        });

        ServiceRateFormComponent.prototype.selectRuleServiceArea(rule, serviceArea);

        assert.strictEqual(rule.selected_geography_type, 'service_area');
        assert.strictEqual(rule.service_area, serviceArea);
        assert.strictEqual(rule.service_area_uuid, 'service_area_1');
        assert.strictEqual(rule.zone, null);
        assert.strictEqual(rule.zone_uuid, null);
        assert.false(rule.is_fallback);
    });

    test('setRuleFallback stores fallback mode and clears geography values', function (assert) {
        const rule = makeRule({
            selected_geography_type: 'zone',
            zone: { id: 'zone_1' },
            zone_uuid: 'zone_1',
            service_area: { id: 'service_area_1' },
            service_area_uuid: 'service_area_1',
            is_fallback: false,
        });

        ServiceRateFormComponent.prototype.setRuleFallback(rule, true);

        assert.strictEqual(rule.selected_geography_type, 'fallback');
        assert.strictEqual(rule.zone, null);
        assert.strictEqual(rule.zone_uuid, null);
        assert.strictEqual(rule.service_area, null);
        assert.strictEqual(rule.service_area_uuid, null);
        assert.true(rule.is_fallback);
    });
});
