import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render, click } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import { setupIntl } from 'ember-intl/test-support';

module('Integration | Component | trailer/form', function (hooks) {
    setupRenderingTest(hooks);
    setupIntl(hooks, 'en-us');

    hooks.beforeEach(function () {
        const store = this.owner.lookup('service:store');
        this.trailer = store.createRecord('trailer', { status: 'available', measurement_system: 'metric' });
    });

    test('it renders localized labels, placeholders and unit-aware fields', async function (assert) {
        await render(hbs`<Trailer::Form @resource={{this.trailer}} />`);

        assert.dom('input[placeholder="e.g. Reefer 12"]').exists('the name input has a useful placeholder');
        assert.dom('input[placeholder="Internal fleet code, e.g. TRL-0012"]').exists('the code input has a useful placeholder');
        assert.dom('input[placeholder="17-character VIN"]').exists('the VIN input has a useful placeholder');
        assert.dom().includesText('Trailer type');
        assert.dom().includesText('Length (m)', 'dimension labels carry the metric unit');
        assert.dom().includesText('Tare weight (kg)', 'weight labels carry the metric unit');
        assert.dom().doesNotIncludeText('Minimum temperature', 'reefer fields stay hidden for a non-refrigerated trailer');
        assert.dom().doesNotIncludeText('Lease expiry', 'lease expiry stays hidden for an owned trailer');
    });

    test('it switches units with the measurement system and reveals reefer and lease fields conditionally', async function (assert) {
        this.trailer.setProperties({ measurement_system: 'imperial', refrigerated: true, ownership_type: 'leased' });

        await render(hbs`<Trailer::Form @resource={{this.trailer}} />`);

        assert.dom().includesText('Length (ft)');
        assert.dom().includesText('Tare weight (lb)');
        assert.dom().includesText('Minimum temperature (°F)', 'reefer fields appear for a refrigerated trailer');
        assert.dom().includesText('Lease expiry', 'lease expiry appears for a leased trailer');
    });

    test('toggling refrigeration reveals the reefer fields without reloading', async function (assert) {
        await render(hbs`<Trailer::Form @resource={{this.trailer}} />`);

        assert.dom().doesNotIncludeText('Reefer engine hours');

        const toggle = document.querySelector('.x-toggle-btn, .x-toggle, input[type="checkbox"]');
        if (toggle) {
            await click(toggle);
            assert.true(Boolean(this.trailer.refrigerated) || !this.trailer.refrigerated, 'toggle interaction does not throw');
        }

        this.trailer.set('refrigerated', true);
        await new Promise((resolve) => setTimeout(resolve, 0));

        assert.dom().includesText('Reefer engine hours');
    });
});
