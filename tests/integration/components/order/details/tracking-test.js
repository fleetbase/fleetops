import Service from '@ember/service';
import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | order/details/tracking', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        this.assignedDriverOrder = null;
        const testContext = this;

        this.owner.register(
            'service:order-actions',
            class OrderActionsService extends Service {
                assignDriver(order) {
                    testContext.assignedDriverOrder = order;
                }
            }
        );
    });

    function buildOrder(overrides = {}) {
        return {
            tracking: 'FLE2177254646SG',
            public_id: 'order_test',
            status: 'started',
            started: true,
            driver_assigned_uuid: 'driver_1',
            driver_assigned: {
                id: 'driver_1',
                name: 'Test Driver',
            },
            tracking_number: {
                qr_code: '',
                barcode: '',
            },
            loadTrackerData() {
                return Promise.resolve();
            },
            tracker_data: {
                provider: 'google_routes',
                confidence: 'high',
                fallback_provider: null,
                generated_at: '2026-05-12T03:49:26.000000Z',
                warnings: [],
                progress: {
                    percentage: 40,
                    completed_stops: 1,
                    remaining_stops: 2,
                },
                eta: {
                    active_stop_seconds: 900,
                    completion_at: '2026-05-12T04:49:26.000000Z',
                    start_seconds: null,
                    start_at: null,
                },
                lifecycle: {
                    status: 'started',
                    mode: 'active',
                    has_started: true,
                    is_terminal: false,
                    show_live_eta: true,
                    show_start_eta: false,
                    message: null,
                },
                active_stop: {
                    uuid: 'stop_1',
                    public_id: 'stop_1',
                    type: 'waypoint',
                    address: '11807 Broadway Lane, Charlotte, 28273, United States',
                },
                stops: [
                    { uuid: 'pickup', type: 'pickup', address: 'Pickup Address', completed: true },
                    { uuid: 'stop_1', public_id: 'stop_1', type: 'waypoint', address: '11807 Broadway Lane, Charlotte, 28273, United States', completed: false },
                    { uuid: 'dropoff', type: 'dropoff', address: 'Dropoff Address', completed: false },
                ],
                route: {
                    distance_m: 91872,
                },
                driver: {
                    online: true,
                    location: {
                        latitude: 35.22,
                        longitude: -80.84,
                    },
                },
                insights: {
                    is_location_stale: false,
                },
                ...overrides.tracker_data,
            },
            ...overrides,
        };
    }

    test('it renders an operator summary instead of provider diagnostics', async function (assert) {
        this.set('order', buildOrder());

        await render(hbs`<Order::Details::Tracking @resource={{this.order}} />`);

        assert.dom().containsText('Smart adjusted ETA');
        assert.dom().containsText('Reported ETA');
        assert.dom().containsText('ETA confidence');
        assert.dom().containsText('NOW HEADING TO - STOP 2 OF 3');
        assert.dom().containsText('Between Stops');
        assert.dom().containsText('Driver live');
        assert.dom().containsText('Provider context: Google Routes route');
        assert.dom().containsText('Diagnostics');
        assert.dom().doesNotContainText('All Stops');
        assert.dom().doesNotContainText('Provider:');
        assert.dom().doesNotContainText('Route Legs');
        assert.dom().doesNotContainText('2026-05-12T04:49:26');
    });

    test('it shows due now for zero second eta', async function (assert) {
        this.set(
            'order',
            buildOrder({
                tracker_data: {
                    eta: {
                        active_stop_seconds: 0,
                        completion_at: null,
                    },
                },
            })
        );

        await render(hbs`<Order::Details::Tracking @resource={{this.order}} />`);

        assert.dom().containsText('Due now');
    });

    test('it shows a fallback warning without listing every provider warning', async function (assert) {
        this.set(
            'order',
            buildOrder({
                tracker_data: {
                    provider: 'calculated',
                    confidence: 'low',
                    fallback_provider: 'calculated',
                    warnings: ['provider_failed:google_routes', 'provider_failed:osrm', 'fallback_used'],
                },
            })
        );

        await render(hbs`<Order::Details::Tracking @resource={{this.order}} />`);

        assert.dom().containsText('Fallback: Calculated');
        assert.dom().containsText('Using Calculated fallback');
        assert.dom().doesNotContainText('Provider Failed Google Routes');
    });

    test('it prioritizes stale driver location as an operator warning', async function (assert) {
        this.set(
            'order',
            buildOrder({
                tracker_data: {
                    driver: {
                        online: true,
                        location: {
                            latitude: 35.22,
                            longitude: -80.84,
                        },
                    },
                    insights: {
                        is_location_stale: true,
                    },
                },
            })
        );

        await render(hbs`<Order::Details::Tracking @resource={{this.order}} />`);

        assert.dom().containsText('Driver stale');
        assert.dom().containsText('Driver location is stale');
        assert.dom().containsText('Ping driver app');
    });

    test('it shows missing driver location for assigned drivers', async function (assert) {
        this.set(
            'order',
            buildOrder({
                tracker_data: {
                    driver: {
                        online: false,
                        location: null,
                    },
                },
            })
        );

        await render(hbs`<Order::Details::Tracking @resource={{this.order}} />`);

        assert.dom().containsText('Driver missing GPS');
        assert.dom().containsText('Driver location is missing');
        assert.dom().containsText('Ping driver app');
    });

    test('it shows assignment state instead of gps recovery when no driver is assigned', async function (assert) {
        const order = buildOrder({
            driver_assigned_uuid: null,
            driver_assigned: null,
            tracker_data: {
                driver: {
                    online: false,
                    location: null,
                },
                eta: {
                    active_stop_seconds: null,
                    completion_at: null,
                },
            },
        });

        this.set('order', order);

        await render(hbs`<Order::Details::Tracking @resource={{this.order}} />`);

        assert.dom().containsText('No driver assigned');
        assert.dom().containsText('Assign driver');
        assert.dom().containsText('Pending driver assignment');
        assert.dom().doesNotContainText('Pending GPS fix');
        assert.dom().doesNotContainText('Ping driver app');

        await click('.tracking-intelligence-alert__cta');

        assert.strictEqual(this.assignedDriverOrder, order);
    });

    test('it hides live eta before the order is started', async function (assert) {
        this.set(
            'order',
            buildOrder({
                status: 'created',
                started: false,
                tracker_data: {
                    eta: {
                        active_stop_seconds: null,
                        completion_at: null,
                    },
                    lifecycle: {
                        status: 'created',
                        mode: 'pre_start',
                        has_started: false,
                        is_terminal: false,
                        show_live_eta: false,
                        show_start_eta: false,
                        message: 'Live ETA will begin once the order is started.',
                    },
                },
            })
        );

        await render(hbs`<Order::Details::Tracking @resource={{this.order}} />`);

        assert.dom().containsText('Tracking pending start');
        assert.dom().containsText('Live ETA will begin once the order is started.');
        assert.dom().doesNotContainText('Smart adjusted ETA');
        assert.dom().doesNotContainText('Reported ETA');
        assert.dom().doesNotContainText('ETA confidence');
    });

    test('it shows estimated start eta for dispatched orders', async function (assert) {
        this.set(
            'order',
            buildOrder({
                status: 'dispatched',
                started: false,
                tracker_data: {
                    eta: {
                        active_stop_seconds: null,
                        completion_at: null,
                        start_seconds: 720,
                        start_at: '2026-05-12T04:01:26.000000Z',
                    },
                    lifecycle: {
                        status: 'dispatched',
                        mode: 'dispatched',
                        has_started: false,
                        is_terminal: false,
                        show_live_eta: false,
                        show_start_eta: true,
                        message: 'Order has been dispatched. Estimated start is based on the assigned driver route to the first stop.',
                    },
                },
            })
        );

        await render(hbs`<Order::Details::Tracking @resource={{this.order}} />`);

        assert.dom().containsText('Order dispatched');
        assert.dom().containsText('Estimated start');
        assert.dom().containsText('Based on the assigned driver route to the first stop');
        assert.dom().doesNotContainText('Smart adjusted ETA');
        assert.dom().doesNotContainText('Reported ETA');
    });

    test('it shows terminal messages instead of eta data', async function (assert) {
        this.set(
            'order',
            buildOrder({
                status: 'completed',
                tracker_data: {
                    eta: {
                        active_stop_seconds: null,
                        completion_at: null,
                    },
                    lifecycle: {
                        status: 'completed',
                        mode: 'completed',
                        has_started: true,
                        is_terminal: true,
                        show_live_eta: false,
                        show_start_eta: false,
                        message: 'Order has been completed.',
                    },
                },
            })
        );

        await render(hbs`<Order::Details::Tracking @resource={{this.order}} />`);

        assert.dom().containsText('Order completed');
        assert.dom().containsText('Order has been completed.');
        assert.dom().doesNotContainText('Smart adjusted ETA');
        assert.dom().doesNotContainText('Reported ETA');
        assert.dom().doesNotContainText('ETA confidence');
    });

    test('it shows canceled terminal messages instead of eta data', async function (assert) {
        this.set(
            'order',
            buildOrder({
                status: 'canceled',
                tracker_data: {
                    eta: {
                        active_stop_seconds: null,
                        completion_at: null,
                    },
                    lifecycle: {
                        status: 'canceled',
                        mode: 'canceled',
                        has_started: false,
                        is_terminal: true,
                        show_live_eta: false,
                        show_start_eta: false,
                        message: 'Order has been canceled.',
                    },
                },
            })
        );

        await render(hbs`<Order::Details::Tracking @resource={{this.order}} />`);

        assert.dom().containsText('Order canceled');
        assert.dom().containsText('Order has been canceled.');
        assert.dom().doesNotContainText('Smart adjusted ETA');
        assert.dom().doesNotContainText('Reported ETA');
    });

    test('it keeps eta visible for custom activity after start', async function (assert) {
        this.set(
            'order',
            buildOrder({
                status: 'arrived_at_pickup',
                started: true,
                tracker_data: {
                    lifecycle: {
                        status: 'arrived_at_pickup',
                        mode: 'active',
                        has_started: true,
                        is_terminal: false,
                        show_live_eta: true,
                        show_start_eta: false,
                        message: null,
                    },
                },
            })
        );

        await render(hbs`<Order::Details::Tracking @resource={{this.order}} />`);

        assert.dom().containsText('Smart adjusted ETA');
        assert.dom().containsText('Reported ETA');
        assert.dom().containsText('NOW HEADING TO - STOP 2 OF 3');
    });
});
