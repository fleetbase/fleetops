import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, findAll, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';
import stubFormInputs, { makeRecord } from 'dummy/tests/helpers/stub-form-inputs';

module('Integration | Component | sensor/form', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        stubFormInputs(this.owner);
        const test = this;
        this.allow = true;
        this.owner.register(
            'service:abilities',
            class extends Service {
                can() {
                    return test.allow;
                }
            }
        );
    });

    test('it renders the sensor inputs and links a telematic provider', async function (assert) {
        this.set(
            'resource',
            makeRecord('sensor', { name: 'Temp probe', serial_number: 'SN-9', internal_id: 'INT-9', unit: 'C', report_frequency_sec: 30, min_threshold: -5, max_threshold: 40 })
        );

        await render(hbs`<Sensor::Form @resource={{this.resource}} />`);

        const values = findAll('input').map((element) => element.value);
        for (const value of ['Temp probe', 'SN-9', 'INT-9', 'C', '30', '-5', '40']) {
            assert.true(values.includes(value), `${value} is bound`);
        }
        assert.dom('[data-test-model-select="telematic"]').isNotDisabled();
        assert.dom('[data-test-model-select="device"]').exists();
        assert.dom('[data-test-model-select="warranty"]').exists();
        assert.dom('[data-test-registry="fleet-ops:component:sensor:form"]').exists();
        assert.dom('[data-test-custom-fields]').exists();

        await click('[data-test-model-select="telematic"]');
        assert.strictEqual(this.resource.telematic.name, 'Picked');
        assert.strictEqual(this.resource.telematic_uuid, 'picked_1');

        await click('[data-test-model-select="device"]');
        assert.strictEqual(this.resource.device.name, 'Picked');
    });

    test('inputs are disabled without write access', async function (assert) {
        this.allow = false;
        this.set('resource', makeRecord('sensor', {}, { isNew: false }));

        await render(hbs`<Sensor::Form @resource={{this.resource}} />`);

        assert.dom('[data-test-model-select="telematic"]').isDisabled();
        assert.true(
            findAll('input[type="number"]').every((element) => element.disabled),
            'numeric inputs are disabled'
        );
    });
});
