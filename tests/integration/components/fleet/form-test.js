import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, findAll, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';
import stubFormInputs, { makeRecord } from 'dummy/tests/helpers/stub-form-inputs';

module('Integration | Component | fleet/form', function (hooks) {
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

    test('it renders the fleet inputs and updates relationships through the selects', async function (assert) {
        this.set('resource', makeRecord('fleet', { name: 'North Fleet', task: 'Deliveries' }));

        await render(hbs`<Fleet::Form @resource={{this.resource}} />`);

        const values = findAll('input').map((element) => element.value);
        assert.true(values.includes('North Fleet') && values.includes('Deliveries'), 'name and task are bound');
        assert.dom('[data-test-model-select="fleet"]').isNotDisabled();
        assert.dom('[data-test-model-select="vendor"]').exists();
        assert.dom('[data-test-model-select="service-area"]').exists();
        assert.dom('[data-test-model-select="zone"]').doesNotExist('the zone select waits for a service area');
        assert.dom('[data-test-registry="fleet-ops:component:fleet:form:details"]').exists();
        assert.dom('[data-test-registry="fleet-ops:component:fleet:form"]').exists();
        assert.dom('[data-test-custom-fields]').exists();

        await click('[data-test-model-select="vendor"]');
        assert.strictEqual(this.resource.vendor.name, 'Picked');

        await click('[data-test-model-select-clear="vendor"]');
        assert.strictEqual(this.resource.vendor, null);
        assert.strictEqual(this.resource.vendor_uuid, null, 'clearing a relationship also clears its uuid');
    });

    test('the zone select appears once a service area is set', async function (assert) {
        this.set('resource', makeRecord('fleet', { service_area: { id: 'sa_1', name: 'Central' } }, { isNew: false }));
        this.allow = false;

        await render(hbs`<Fleet::Form @resource={{this.resource}} />`);

        assert.dom('[data-test-model-select="zone"]').isDisabled();
        assert.dom('[data-test-model-select="fleet"]').isDisabled();
    });
});
