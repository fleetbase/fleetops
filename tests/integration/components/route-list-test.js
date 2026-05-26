import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | route-list', function (hooks) {
    setupRenderingTest(hooks);

    function place(uuid, street1, latitude, longitude) {
        return {
            id: uuid,
            uuid,
            public_id: uuid,
            street1,
            address: street1,
            latitude,
            longitude,
        };
    }

    test('it renders per-stop route eta from tracker legs', async function (assert) {
        const pickup = place('pickup', 'Pickup Street', 1.3, 103.8);
        const dropoff = place('dropoff', 'Dropoff Street', 1.4, 103.9);

        this.set('order', {
            public_id: 'order_test',
            payload: {
                pickup,
                waypoints: [],
                dropoff,
            },
            tracker_data: {
                active_stop: { uuid: 'dropoff' },
                stops: [
                    { uuid: 'pickup', public_id: 'pickup', completed: true },
                    { uuid: 'dropoff', public_id: 'dropoff', completed: false },
                ],
                route: {
                    legs: [
                        {
                            stop: { uuid: 'dropoff', public_id: 'dropoff' },
                            eta_seconds: 1800,
                            eta_at: '2026-05-12T04:49:26.000000Z',
                        },
                    ],
                },
            },
        });

        await render(hbs`<RouteList @order={{this.order}} />`);

        assert.dom().containsText('Completed');
        assert.dom().containsText('Current Stop');
        assert.dom().containsText('ETA:');
        assert.dom().containsText('Arrives:');
    });

    test('it can hide per-stop route eta while keeping stops visible', async function (assert) {
        const pickup = place('pickup', 'Pickup Street', 1.3, 103.8);
        const dropoff = place('dropoff', 'Dropoff Street', 1.4, 103.9);

        this.set('order', {
            public_id: 'order_test',
            status: 'dispatched',
            payload: {
                pickup,
                waypoints: [],
                dropoff,
            },
            tracker_data: {
                active_stop: { uuid: 'dropoff' },
                stops: [
                    { uuid: 'pickup', public_id: 'pickup', completed: false },
                    { uuid: 'dropoff', public_id: 'dropoff', completed: false },
                ],
                route: {
                    legs: [
                        {
                            stop: { uuid: 'dropoff', public_id: 'dropoff' },
                            eta_seconds: 1800,
                            eta_at: '2026-05-12T04:49:26.000000Z',
                        },
                    ],
                },
            },
        });

        await render(hbs`<RouteList @order={{this.order}} @showEta={{false}} />`);

        assert.dom().containsText('Pickup Street');
        assert.dom().containsText('Dropoff Street');
        assert.dom().containsText('Current Stop');
        assert.dom().doesNotContainText('ETA:');
        assert.dom().doesNotContainText('Arrives:');
    });
});
