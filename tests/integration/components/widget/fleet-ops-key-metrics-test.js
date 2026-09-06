import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { findAll, render, settled } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';

module('Integration | Component | widget/fleet-ops-key-metrics', function (hooks) {
    setupRenderingTest(hooks);

    test('it shows a spinner while loading, then the formatted top metrics in priority order', async function (assert) {
        let resolveMetrics;
        this.owner.register(
            'service:fetch',
            class extends Service {
                get() {
                    return new Promise((resolve) => {
                        resolveMetrics = resolve;
                    });
                }
            }
        );

        render(hbs`<Widget::FleetOpsKeyMetrics />`);
        await new Promise((resolve) => requestAnimationFrame(resolve));
        assert.dom('.fleet-ops-key-metrics').exists();

        resolveMetrics({ total_distance_traveled: null, fuel_costs: 500, earnings: null, orders_completed: 7, orders_in_progress: null, drivers_online: 3, ignored_metric: 99 });
        await settled();

        assert.deepEqual(
            findAll('.fleet-ops-key-metrics .grid > div').map((element) => element.textContent.replace(/\s+/g, ' ').trim()),
            ['Earnings $0.00', 'Orders Completed 7', 'Orders In Progress 0', 'Drivers Online 3', 'Fuel Costs $5.00', 'Total Distance Traveled 0km']
        );
    });

    test('an empty or missing metrics payload renders no tiles', async function (assert) {
        this.owner.register(
            'service:fetch',
            class extends Service {
                async get() {
                    return undefined;
                }
            }
        );

        await render(hbs`<Widget::FleetOpsKeyMetrics />`);

        assert.dom('.fleet-ops-key-metrics .grid > div').doesNotExist();
        assert.dom(this.element).includesText('Legacy');
    });
});
