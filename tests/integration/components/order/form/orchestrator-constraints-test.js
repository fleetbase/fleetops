import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import OrderFormOrchestratorConstraintsComponent from '@fleetbase/fleetops-engine/components/order/form/orchestrator-constraints';

module('Integration | Component | order/form/orchestrator-constraints', function (hooks) {
    setupRenderingTest(hooks);

    test('it renders optional orchestrator constraint inputs', async function (assert) {
        this.set('resource', {
            required_skills: [],
            orchestrator_priority: 50,
        });

        await render(hbs`<Order::Form::OrchestratorConstraints @resource={{this.resource}} />`);

        assert.dom().containsText('Orchestrator Constraints');
        assert.dom().containsText('Time Window Start');
        assert.dom().containsText('Time Window End');
        assert.dom().containsText('Required Skills');
        assert.dom().containsText('Orchestrator Priority');
    });

    test('it normalizes epoch-only time window values against the order date', function (assert) {
        const resource = {
            scheduled_at: new Date(2026, 5, 18),
        };
        const component = new OrderFormOrchestratorConstraintsComponent(this.owner, { resource });

        component.setTimeWindow('time_window_start', new Date(1970, 0, 1, 9, 30));

        assert.strictEqual(resource.time_window_start.getFullYear(), 2026);
        assert.strictEqual(resource.time_window_start.getMonth(), 5);
        assert.strictEqual(resource.time_window_start.getDate(), 18);
        assert.strictEqual(resource.time_window_start.getHours(), 9);
        assert.strictEqual(resource.time_window_start.getMinutes(), 30);
    });
});
