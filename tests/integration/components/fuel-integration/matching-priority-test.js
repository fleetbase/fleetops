import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | fuel-integration/matching-priority', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        this.order = ['plate_number', 'internal_id'];
        this.setOrder = (order) => this.set('order', order);
    });

    test('it renders selected identifiers as an ordered priority list', async function (assert) {
        await render(hbs`<FuelIntegration::MatchingPriority @order={{this.order}} @onChange={{this.setOrder}} @editable={{true}} />`);

        assert.dom('[data-test-matching-priority-row]').exists({ count: 2 });
        assert.dom('[data-test-matching-priority-row="plate_number"] [data-test-matching-priority-rank]').hasText('Priority 1');
        assert.dom('[data-test-matching-priority-row="plate_number"] [data-test-matching-priority-map]').hasText('Provider plate number -> Vehicle plate number');
        assert.dom('[data-test-matching-priority-row="internal_id"] [data-test-matching-priority-rank]').hasText('Priority 2');
    });

    test('it adds and removes identifiers between available and selected lists', async function (assert) {
        await render(hbs`<FuelIntegration::MatchingPriority @order={{this.order}} @onChange={{this.setOrder}} @editable={{true}} />`);

        assert.dom('[data-test-matching-available="fuel_card_number"]').exists();
        assert.dom('[data-test-matching-available="provider_vehicle_id"]').exists();

        await click('[data-test-matching-available="fuel_card_number"]');

        assert.deepEqual(this.order, ['plate_number', 'internal_id', 'fuel_card_number']);
        assert.dom('[data-test-matching-priority-row="fuel_card_number"] [data-test-matching-priority-rank]').hasText('Priority 3');
        assert.dom('[data-test-matching-available="fuel_card_number"]').doesNotExist();

        await click('[data-test-matching-remove="plate_number"]');

        assert.deepEqual(this.order, ['internal_id', 'fuel_card_number']);
        assert.dom('[data-test-matching-priority-row="plate_number"]').doesNotExist();
        assert.dom('[data-test-matching-available="plate_number"]').exists();
    });

    test('it moves selected identifiers up and down', async function (assert) {
        this.set('order', ['internal_id', 'plate_number']);

        await render(hbs`<FuelIntegration::MatchingPriority @order={{this.order}} @onChange={{this.setOrder}} @editable={{true}} />`);

        await click('[data-test-matching-move-up="plate_number"]');

        assert.deepEqual(this.order, ['plate_number', 'internal_id']);
        assert.dom('[data-test-matching-priority-row="plate_number"] [data-test-matching-priority-rank]').hasText('Priority 1');
        assert.dom('[data-test-matching-priority-row="internal_id"] [data-test-matching-priority-rank]').hasText('Priority 2');

        await click('[data-test-matching-move-down="plate_number"]');

        assert.deepEqual(this.order, ['internal_id', 'plate_number']);
        assert.dom('[data-test-matching-priority-row="internal_id"] [data-test-matching-priority-rank]').hasText('Priority 1');
    });

    test('it explains the manual review path when no identifiers are selected', async function (assert) {
        this.set('order', []);

        await render(hbs`<FuelIntegration::MatchingPriority @order={{this.order}} @onChange={{this.setOrder}} @editable={{true}} />`);

        assert.dom('[data-test-matching-empty-state]').hasText('No automatic identifiers are selected. Imported transactions will stay unmatched until reviewed manually.');
        assert.dom('[data-test-matching-available]').exists({ count: 8 });
    });

    test('it renders production defaults when no order is provided', async function (assert) {
        this.set('order', undefined);

        await render(hbs`<FuelIntegration::MatchingPriority @order={{this.order}} @onChange={{this.setOrder}} @editable={{true}} />`);

        assert.dom('[data-test-matching-priority-row="plate_number"] [data-test-matching-priority-rank]').hasText('Priority 1');
        assert.dom('[data-test-matching-priority-row="internal_id"] [data-test-matching-priority-rank]').hasText('Priority 2');
        assert.dom('[data-test-matching-available="provider_vehicle_id"]').exists();
    });

    test('it displays legacy saved matching keys with the new field names', async function (assert) {
        this.set('order', ['internal_number', 'vehicle_card_id']);

        await render(hbs`<FuelIntegration::MatchingPriority @order={{this.order}} @onChange={{this.setOrder}} @editable={{true}} />`);

        assert.dom('[data-test-matching-priority-row="internal_id"]').exists();
        assert.dom('[data-test-matching-priority-row="fuel_card_number"]').exists();
    });

    test('it upgrades the old provider-first default to the production default', async function (assert) {
        this.set('order', ['provider_vehicle_id', 'internal_number', 'structure_number', 'plate_number', 'vehicle_card_id', 'trip_number']);

        await render(hbs`<FuelIntegration::MatchingPriority @order={{this.order}} @onChange={{this.setOrder}} @editable={{true}} />`);

        assert.dom('[data-test-matching-priority-row="plate_number"] [data-test-matching-priority-rank]').hasText('Priority 1');
        assert.dom('[data-test-matching-priority-row="internal_id"] [data-test-matching-priority-rank]').hasText('Priority 2');
        assert.dom('[data-test-matching-available="provider_vehicle_id"]').exists();
    });
});
