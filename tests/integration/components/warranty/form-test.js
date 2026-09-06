import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';
import stubFormInputs, { makeRecord } from 'dummy/tests/helpers/stub-form-inputs';

module('Integration | Component | warranty/form', function (hooks) {
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

    test('it renders the warranty inputs bound to the record', async function (assert) {
        this.set('resource', makeRecord('warranty', { provider: 'Acme', policy_number: 'POL-1', terms: 'Terms', policy: 'Policy' }));

        await render(hbs`<Warranty::Form @resource={{this.resource}} />`);

        assert.dom('input[placeholder="Provider"]').hasValue('Acme').isNotDisabled();
        assert.dom('input[placeholder="Policy Number"]').hasValue('POL-1');
        assert.dom('[data-test-model-select="vendor"]').isNotDisabled();
        assert.dom('[data-test-date-picker="Select Start Date"]').exists();
        assert.dom('[data-test-date-picker="Select End Date"]').exists();
        assert.dom('textarea[aria-label="Terms"]').hasValue('Terms');
        assert.dom('textarea[aria-label="Policy"]').hasValue('Policy');
        assert.dom('[data-test-registry="fleet-ops:component:warranty:form:details"]').exists();
        assert.dom('[data-test-registry="fleet-ops:component:warranty:form"]').exists();
        assert.dom('[data-test-custom-fields]').exists();
    });

    test('every input is disabled without write access', async function (assert) {
        this.allow = false;
        this.set('resource', makeRecord('warranty'));

        await render(hbs`<Warranty::Form @resource={{this.resource}} />`);

        assert.dom('input[placeholder="Provider"]').isDisabled();
        assert.dom('input[placeholder="Policy Number"]').isDisabled();
        assert.dom('[data-test-model-select="vendor"]').isDisabled();
        assert.dom('[data-test-date-picker="Select Start Date"]').isDisabled();
        assert.dom('textarea[aria-label="Terms"]').isDisabled();
        assert.dom('textarea[aria-label="Policy"]').isDisabled();
    });
});
