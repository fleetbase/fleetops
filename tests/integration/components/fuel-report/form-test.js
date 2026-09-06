import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';
import stubFormInputs, { makeRecord } from 'dummy/tests/helpers/stub-form-inputs';

module('Integration | Component | fuel-report/form', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        stubFormInputs(this.owner);
        const asked = (this.asked = []);
        this.allow = true;
        const test = this;
        this.owner.register(
            'service:abilities',
            class extends Service {
                can(permission) {
                    asked.push(permission);
                    return test.allow;
                }
            }
        );
    });

    test('it renders the report inputs and sets the reporter from the user select', async function (assert) {
        this.set('resource', makeRecord('fuel-report', { odometer: 1200, currency: 'USD' }));

        await render(hbs`<FuelReport::Form @resource={{this.resource}} />`);

        assert.dom('[data-test-model-select="user"]').isNotDisabled();
        assert.dom('[data-test-model-select="driver"]').exists();
        assert.dom('[data-test-model-select="vehicle"]').exists();
        assert.dom('input[type="number"]').hasValue('1200');
        assert.dom('[data-test-money-input]').exists();
        assert.dom('[data-test-unit-input]').exists();
        assert.dom('[data-test-registry="fleet-ops:component:fuel-report:form:details"]').exists();
        assert.dom('[data-test-registry="fleet-ops:component:fuel-report:form"]').exists();
        assert.dom('[data-test-custom-fields]').exists();
        assert.true(this.asked.includes('fleet-ops create fuel-report'), 'write access is checked for the new record');

        await click('[data-test-model-select="user"]');
        assert.strictEqual(this.resource.reporter.name, 'Picked');
        assert.strictEqual(this.resource.reported_by_uuid, 'picked_1');

        await click('[data-test-model-select="driver"]');
        assert.strictEqual(this.resource.driver.name, 'Picked', 'the driver select writes straight to the record');
    });

    test('inputs are disabled when the user cannot write the record', async function (assert) {
        this.allow = false;
        this.set('resource', makeRecord('fuel-report', {}, { isNew: false }));

        await render(hbs`<FuelReport::Form @resource={{this.resource}} />`);

        assert.dom('[data-test-model-select="user"]').isDisabled();
        assert.dom('[data-test-money-input]').isDisabled();
        assert.dom('[data-test-unit-input]').isDisabled();
        assert.true(this.asked.includes('fleet-ops update fuel-report'), 'a persisted record asks for update access');
    });
});
