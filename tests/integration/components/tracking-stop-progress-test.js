import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | tracking-stop-progress', function (hooks) {
    setupRenderingTest(hooks);

    test('it renders completed active and pending stops', async function (assert) {
        this.set('stops', [
            { uuid: 'pickup', type: 'pickup', address: 'Pickup Address', completed: true },
            { uuid: 'waypoint', type: 'waypoint', address: 'Active Stop', completed: false },
            { uuid: 'dropoff', type: 'dropoff', address: 'Dropoff Address', completed: false },
        ]);
        this.set('activeStop', { uuid: 'waypoint' });

        await render(hbs`<TrackingStopProgress @stops={{this.stops}} @activeStop={{this.activeStop}} />`);

        assert.dom().containsText('Route Progress');
        assert.dom().containsText('1 / 3 stops');
        assert.dom().containsText('Pickup Address');
        assert.dom().containsText('Active Stop');
        assert.dom().containsText('Dropoff Address');
    });
});
