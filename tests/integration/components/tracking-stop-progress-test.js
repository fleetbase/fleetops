import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { findAll, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

function dots() {
    return findAll('.tracking-stop-progress__dot').map((dot) => ({
        label: dot.textContent.trim(),
        title: dot.getAttribute('title'),
        state: dot.classList.contains('tracking-stop-progress__dot--done')
            ? 'done'
            : dot.classList.contains('tracking-stop-progress__dot--active')
              ? 'active'
              : dot.classList.contains('tracking-stop-progress__dot--pending')
                ? 'pending'
                : 'none',
    }));
}

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

        assert.dom().containsText('Between Stops');
        assert.dom().containsText('1 / 3 stops');
        assert.dom('.tracking-stop-progress__dot').exists({ count: 3 });
        assert.dom('.tracking-stop-progress__dot--done').exists({ count: 1 });
        assert.dom('.tracking-stop-progress__dot--active').exists({ count: 1 });
        assert.dom('.tracking-stop-progress__dot--pending').exists({ count: 1 });
        assert.deepEqual(dots(), [
            { label: 'P', title: 'Pickup', state: 'done' },
            { label: '2', title: 'Stop 2', state: 'active' },
            { label: 'D', title: 'Dropoff', state: 'pending' },
        ]);
        assert.dom('.tracking-stop-progress__connector').exists({ count: 4 }, 'first and last stops drop their outer connector');
    });

    test('the active stop is matched by public id and a completed active stop is done, not active', async function (assert) {
        this.set('stops', [
            { public_id: 'stop_a', type: 'waypoint', city: 'Austin', completed: true },
            { public_id: 'stop_b', type: 'waypoint', name: 'Depot', completed: false },
            { public_id: 'stop_c', type: 'waypoint', completed: false },
        ]);
        this.set('activeStop', { public_id: 'stop_a' });

        await render(hbs`<TrackingStopProgress @stops={{this.stops}} @activeStop={{this.activeStop}} />`);

        assert.deepEqual(
            dots().map((dot) => dot.state),
            ['done', 'pending', 'pending']
        );
        assert.deepEqual(
            dots().map((dot) => dot.label),
            ['1', '2', '3']
        );
        assert.dom().containsText('1 / 3 stops');
    });

    test('without stops or an active stop it renders an empty rail', async function (assert) {
        await render(hbs`<TrackingStopProgress />`);
        assert.dom().containsText('0 / 0 stops');
        assert.dom('.tracking-stop-progress__dot').doesNotExist();

        this.set('stops', [{ uuid: 'only', type: 'pickup', street1: '1 Main St', country: 'US' }]);
        await render(hbs`<TrackingStopProgress @stops={{this.stops}} />`);
        assert.deepEqual(dots(), [{ label: 'P', title: 'Pickup', state: 'pending' }]);
        assert.dom('.tracking-stop-progress__connector').doesNotExist('a single stop has no connectors');
    });
});
