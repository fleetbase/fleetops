import Service from '@ember/service';
import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, findAll, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | order/details/tracking', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        this.assignedDriverOrder = null;
        const testContext = this;

        this.owner.register(
            'service:abilities',
            class extends Service {
                can() {
                    return true;
                }

                cannot() {
                    return false;
                }
            }
        );
        this.owner.register(
            'service:order-actions',
            class OrderActionsService extends Service {
                assignDriver(order) {
                    testContext.assignedDriverOrder = order;
                }

                viewLabel(order) {
                    testContext.viewedLabelOrder = order;
                }
            }
        );
    });

    function buildOrder(overrides = {}) {
        const { tracker_data: trackerOverrides, ...orderOverrides } = overrides;

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
                ...trackerOverrides,
            },
            ...orderOverrides,
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

        // "Due now" was dropped in 9356fb67 (Tighten tracking route UI refinements); a zero ETA renders as 0s.
        assert.dom('.tracking-intelligence-destination__eta').containsText('0s');
        assert.dom('.tracking-intelligence-cell__value').hasText('Pending start');
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
    test('without tracker data it renders only the labels and skips the load', async function (assert) {
        this.set('order', { tracking: 'FLE1', public_id: 'order_plain', tracking_number: { qr_code: '', barcode: '' } });

        await render(hbs`<Order::Details::Tracking @resource={{this.order}} />`);

        assert.dom('.tracking-intelligence').doesNotExist();
        assert.dom('img').exists({ count: 2 });
        assert.dom().containsText('Get Order Label');

        await click(findAll('button').find((button) => /Get Order Label/.test(button.textContent)));
        assert.strictEqual(this.viewedLabelOrder, this.order);

        this.set('order', undefined);
        await render(hbs`<Order::Details::Tracking @resource={{this.order}} />`);
        assert.dom('.tracking-intelligence').doesNotExist();
    });

    test('recalculate reloads the tracker data and a failing load is swallowed', async function (assert) {
        let loads = 0;
        let fail = false;
        this.set(
            'order',
            buildOrder({
                loadTrackerData() {
                    loads++;
                    return fail ? Promise.reject(new Error('tracker down')) : Promise.resolve();
                },
            })
        );

        await render(hbs`<Order::Details::Tracking @resource={{this.order}} />`);
        assert.strictEqual(loads, 1, 'loaded once on construction');
        assert.dom('.tracking-intelligence-footer__button').hasText('Recalculate');

        fail = true;
        await click('.tracking-intelligence-footer__button');
        assert.strictEqual(loads, 2);
        assert.dom('.tracking-intelligence-footer__button').hasText('Recalculate');
        assert.dom().containsText('Updated 12 May');
    });

    test('it derives the lifecycle from the order when the tracker sends none', async function (assert) {
        const base = { eta: { active_stop_seconds: null, completion_at: null, start_seconds: null }, lifecycle: undefined };

        this.set('order', buildOrder({ status: 'completed', tracker_data: base }));
        await render(hbs`<Order::Details::Tracking @resource={{this.order}} />`);
        assert.dom('.tracking-intelligence-alert__title').hasText('Order completed');
        assert.dom().containsText('Order has been completed.');
        assert.dom('[data-icon="circle-check"]').exists();
        assert.dom().doesNotContainText('Smart adjusted ETA');

        this.set('order', buildOrder({ status: 'canceled', tracker_data: base }));
        await render(hbs`<Order::Details::Tracking @resource={{this.order}} />`);
        assert.dom('.tracking-intelligence-alert__title').hasText('Order canceled');
        assert.dom('[data-icon="ban"]').exists();

        this.set('order', buildOrder({ status: 'started', driver_assigned: null, driver_assigned_uuid: null, tracker_data: base }));
        await render(hbs`<Order::Details::Tracking @resource={{this.order}} />`);
        assert.dom('.tracking-intelligence-alert__title').hasText('No driver assigned', 'unassigned orders get the operator warning, no lifecycle message');
        assert.dom('.tracking-intelligence-alert').exists({ count: 1 });
        assert.dom().containsText('Pending driver assignment');

        this.set('order', buildOrder({ status: 'dispatched', started: false, tracker_data: { ...base, eta: { ...base.eta, start_seconds: 600 } } }));
        await render(hbs`<Order::Details::Tracking @resource={{this.order}} />`);
        assert.dom('.tracking-intelligence-alert__title').hasText('Order dispatched');
        assert.dom('[data-icon="route"]').exists();
        assert.dom().containsText('Estimated start');
        assert.dom().containsText('10m');

        this.set('order', buildOrder({ status: 'dispatched', started: false, tracker_data: base }));
        await render(hbs`<Order::Details::Tracking @resource={{this.order}} />`);
        assert.dom('.tracking-intelligence__eta-grid').doesNotExist('no start ETA means no start panel');
        assert.dom().doesNotContainText('Smart adjusted ETA');

        this.set('order', buildOrder({ status: 'created', started: false, tracker_data: base }));
        await render(hbs`<Order::Details::Tracking @resource={{this.order}} />`);
        assert.dom('.tracking-intelligence-alert__title').hasText('Tracking pending start');
        assert.dom('[data-icon="clock"]').exists();
        assert.dom().containsText('Live ETA will begin once the order is started.');

        this.set('order', buildOrder({ status: undefined, started: undefined, started_at: '2026-05-12T03:00:00Z', tracker_data: base }));
        await render(hbs`<Order::Details::Tracking @resource={{this.order}} />`);
        assert.dom().containsText('Smart adjusted ETA', 'a started_at timestamp counts as started');
        assert.dom('.tracking-intelligence-alert').doesNotExist();

        this.set('order', buildOrder({ status: 'started', started: undefined, tracker_data: base }));
        await render(hbs`<Order::Details::Tracking @resource={{this.order}} />`);
        assert.dom().containsText('Smart adjusted ETA', 'a started status counts as started');
    });

    test('it renders confidence, warnings and diagnostics from the tracker data', async function (assert) {
        this.set(
            'order',
            buildOrder({
                tracker_data: { confidence: 'medium', options: { traffic_enabled: true }, driver: { online: true, location: { latitude: 1, longitude: 2 }, location_age_seconds: 120 } },
            })
        );
        await render(hbs`<Order::Details::Tracking @resource={{this.order}} />`);
        assert.dom('.tracking-intelligence-pill--warn').exists();
        assert.dom().containsText('Medium confidence · 68%');
        assert.dom('.tracking-intelligence-confidence__segments .is-lit').exists({ count: 3 });
        assert.dom().containsText('Driver live · 2m');
        assert.dom('.tracking-intelligence-alert__title').hasText('Tracking estimate warning');
        assert.dom().containsText('Medium confidence ETA. Treat the estimate as directional.');
        assert.dom('.tracking-intelligence-cell__sub--warn').hasText('Medium confidence ETA. Treat the estimate as directional.');
        assert.deepEqual(
            findAll('.tracking-intelligence-diagnostics__row').map((row) => row.textContent.replace(/\s+/g, ' ').trim()),
            ['Provider Google Routes', 'Fallback No', 'Traffic aware Yes', 'Confidence Medium', 'Driver signal Live', 'Warnings 0']
        );
        assert.dom('.tracking-intelligence-diagnostics__summary').containsText('0 warnings');

        this.set('order', buildOrder({ tracker_data: { confidence: 'low', confidence_score: '150', warnings: ['provider_failed:osrm'] } }));
        await render(hbs`<Order::Details::Tracking @resource={{this.order}} />`);
        assert.dom().containsText('Low confidence · 100%', 'an explicit score wins and is clamped');
        assert.dom('.tracking-intelligence-pill--bad').exists();
        assert.dom('.tracking-intelligence-diagnostics__summary').containsText('1 warning');
        assert.dom('.tracking-intelligence-diagnostics__warnings').hasText('provider_failed:osrm');

        this.set('order', buildOrder({ tracker_data: { confidence: 'high', warnings: ['provider_failed:google_routes', 'other'] } }));
        await render(hbs`<Order::Details::Tracking @resource={{this.order}} />`);
        assert.dom('.tracking-intelligence-alert__body').hasText('Tracking provider returned an error. Showing the best available estimate.');
        assert.dom('.tracking-intelligence-confidence__segments .is-lit').exists({ count: 5 });

        this.set('order', buildOrder({ tracker_data: { confidence: null, warnings: ['unrelated'] } }));
        await render(hbs`<Order::Details::Tracking @resource={{this.order}} />`);
        assert.dom().containsText('Unknown confidence · 0%');
        assert.dom('.tracking-intelligence-pill--muted').exists();
        assert.dom('.tracking-intelligence-confidence__segments .is-lit').exists({ count: 1 });
        assert.dom('.tracking-intelligence-alert').doesNotExist('no operator warning when nothing is wrong');

        this.set('order', buildOrder({ tracker_data: { confidence: 'low', confidence_percent: 12.4 } }));
        await render(hbs`<Order::Details::Tracking @resource={{this.order}} />`);
        assert.dom().containsText('Low confidence · 12%');
        assert.dom('.tracking-intelligence-confidence__segments .is-lit').exists({ count: 2 });
    });

    test('it labels the active stop and reads the reported eta from the order', async function (assert) {
        const stops = [
            { id: 'p', type: 'pickup', address: 'Pickup', completed: true },
            { id: 'w', public_id: 'w_pub', type: 'waypoint', address: 'Waypoint', completed: false },
            { uuid: 'd', type: 'dropoff', address: 'Dropoff', completed: false },
        ];

        this.set(
            'order',
            buildOrder({
                eta: { p: 300 },
                tracker_data: {
                    stops,
                    active_stop: { id: 'p', type: 'pickup', address: 'Pickup' },
                    eta: { active_stop_seconds: null, completion_at: null },
                    route: { distance_m: 5000, duration_s: 120 },
                    progress: {},
                },
            })
        );
        await render(hbs`<Order::Details::Tracking @resource={{this.order}} />`);
        assert.dom('.tracking-intelligence-destination__marker').hasText('P');
        assert.dom('.tracking-intelligence-destination__label').hasText('NOW HEADING TO - STOP 1 OF 3');
        assert.dom('.tracking-intelligence-cell__value--muted').doesNotExist();
        assert.dom(findAll('.tracking-intelligence-cell__value')[1]).hasText('5m', 'the reported ETA comes from the order eta map keyed by id');
        assert.dom(findAll('.tracking-intelligence-cell__value')[0]).hasText('2m', 'the smart ETA falls back to the route duration');
        assert.dom('.tracking-intelligence-distance__bar span').hasAttribute('style', 'width: 33%;', 'progress derives from completed stops');

        this.set(
            'order',
            buildOrder({
                eta: { d: 120 },
                tracker_data: {
                    stops,
                    active_stop: { uuid: 'd', type: 'dropoff' },
                    eta: { active_stop_seconds: null },
                    route: { distance_m: 0, legs: [{ distance_m: 800, progress_percentage: 40 }] },
                    progress: { percentage: 0 },
                },
            })
        );
        await render(hbs`<Order::Details::Tracking @resource={{this.order}} />`);
        assert.dom('.tracking-intelligence-destination__marker').hasText('D');
        assert.dom(findAll('.tracking-intelligence-cell__value')[1]).hasText('2m');
        assert.dom(findAll('.tracking-intelligence-distance__bar span')[0]).hasAttribute('style', 'width: 2%;', 'a zero progress with distance still shows a sliver');
        assert.dom(findAll('.tracking-intelligence-distance__bar span')[1]).hasAttribute('style', 'width: 40%;');
        assert.dom(findAll('.tracking-intelligence-distance__row strong')[1]).hasText('1km');

        this.set(
            'order',
            buildOrder({
                eta: { w_pub: 60 },
                tracker_data: {
                    stops,
                    active_stop: { public_id: 'w_pub', type: 'waypoint', eta_seconds: null },
                    eta: { active_stop_seconds: null },
                    route: { distance_m: null },
                    progress: { active_leg_percentage: 55 },
                },
            })
        );
        await render(hbs`<Order::Details::Tracking @resource={{this.order}} />`);
        assert.dom('.tracking-intelligence-destination__marker').hasText('2');
        assert.dom(findAll('.tracking-intelligence-cell__value')[1]).hasText('1m');
        assert.dom(findAll('.tracking-intelligence-distance__row strong')[0]).hasText('-');
        assert.dom(findAll('.tracking-intelligence-distance__bar span')[1]).hasAttribute('style', 'width: 55%;');

        this.set(
            'order',
            buildOrder({
                tracker_data: {
                    stops: [],
                    active_stop: null,
                    eta: { active_stop_seconds: null },
                    route: null,
                    progress: {},
                    driver: { online: false, location: { latitude: 1, longitude: 2 } },
                },
            })
        );
        await render(hbs`<Order::Details::Tracking @resource={{this.order}} />`);
        assert.dom('.tracking-intelligence-destination__marker').hasText('•');
        assert.dom('.tracking-intelligence-destination__label').hasText('NOW HEADING TO');
        assert.dom().containsText('Driver offline');
        assert.dom(findAll('.tracking-intelligence-cell__value')[0]).hasText('Pending start');
        assert.dom(findAll('.tracking-intelligence-cell__value')[1]).hasText('-');
        assert.dom(findAll('.tracking-intelligence-distance__bar span')[0]).hasAttribute('style', 'width: 0%;');
        assert.dom(findAll('.tracking-intelligence-distance__bar span')[1]).hasAttribute('style', 'width: 8%;', 'the leg bar is clamped to at least 8%');
    });

    test('the leg progress falls back by driver signal', async function (assert) {
        const base = { eta: { active_stop_seconds: null }, route: { distance_m: 100 }, progress: { percentage: 50 } };

        this.set('order', buildOrder({ tracker_data: { ...base, insights: { is_location_stale: true } } }));
        await render(hbs`<Order::Details::Tracking @resource={{this.order}} />`);
        assert.dom(findAll('.tracking-intelligence-distance__bar span')[1]).hasAttribute('style', 'width: 18%;');
        assert.dom('.tracking-intelligence-cell__value--muted').exists();

        this.set('order', buildOrder({ tracker_data: { ...base, driver: { online: true, location: null } } }));
        await render(hbs`<Order::Details::Tracking @resource={{this.order}} />`);
        assert.dom(findAll('.tracking-intelligence-distance__bar span')[1]).hasAttribute('style', 'width: 0%;');
        assert.dom('.tracking-intelligence-alert--critical').exists();
    });

    test('pinging the driver app posts to the api and reports the outcome', async function (assert) {
        const posts = [];
        const notes = [];
        let failure = null;
        this.owner.register(
            'service:fetch',
            class extends Service {
                async post(url) {
                    posts.push(url);
                    if (failure) {
                        throw failure;
                    }
                }
            }
        );
        this.owner.register(
            'service:notifications',
            class extends Service {
                success(message) {
                    notes.push(['success', message]);
                }

                error(message) {
                    notes.push(['error', message]);
                }
            }
        );
        this.set('order', buildOrder({ id: 'order_1', tracker_data: { driver: { online: true, location: null } } }));

        await render(hbs`<Order::Details::Tracking @resource={{this.order}} />`);

        await click('.tracking-intelligence-alert__cta');
        assert.deepEqual(posts, ['orders/order_1/ping-driver']);
        assert.deepEqual(notes, [['success', 'Driver app ping sent.']]);

        failure = new Error('driver offline');
        await click('.tracking-intelligence-alert__cta');
        assert.deepEqual(notes.at(-1), ['error', 'driver offline']);

        failure = {};
        await click('.tracking-intelligence-alert__cta');
        assert.deepEqual(notes.at(-1), ['error', 'Unable to ping driver app.']);
    });

    test('assign driver is a no-op when the order actions cannot assign', async function (assert) {
        this.owner.register(
            'service:abilities',
            class extends Service {
                can() {
                    return true;
                }

                cannot() {
                    return false;
                }
            }
        );
        this.owner.register(
            'service:order-actions',
            class extends Service {
                viewLabel() {}
            }
        );
        this.set('order', buildOrder({ driver_assigned: null, driver_assigned_uuid: null }));

        await render(hbs`<Order::Details::Tracking @resource={{this.order}} />`);

        await click('.tracking-intelligence-alert__cta');
        assert.strictEqual(this.assignedDriverOrder, null);
    });
    test('server lifecycle flags and sparse tracker payloads are honoured', async function (assert) {
        this.set(
            'order',
            buildOrder({ tracker_data: { lifecycle: { mode: 'active', message: 'Driver reported a delay.', show_live_eta: true }, warnings: undefined, provider: undefined } })
        );
        await render(hbs`<Order::Details::Tracking @resource={{this.order}} />`);
        assert.dom('.tracking-intelligence-alert__body').hasText('Driver reported a delay.');
        assert.dom('.tracking-intelligence-alert__title').hasText('', 'an active-mode message has no title');
        assert.dom('[data-icon="clock"]').exists();
        assert.dom('.tracking-intelligence-diagnostics__summary').containsText('0 warnings');
        assert.dom().containsText('Provider context: route');
        assert.dom('.tracking-intelligence-diagnostics__warnings').doesNotExist();

        this.set('order', buildOrder({ tracker_data: { lifecycle: { mode: 'created', message: 'Waiting.', show_live_eta: false }, stops: undefined, progress: {}, active_stop: null } }));
        await render(hbs`<Order::Details::Tracking @resource={{this.order}} />`);
        assert.dom('.tracking-intelligence-alert__title').hasText('Tracking pending start');
        assert.dom().doesNotContainText('Smart adjusted ETA');

        this.set('order', buildOrder({ tracker_data: { lifecycle: { mode: 'dispatched', show_live_eta: true, show_start_eta: false }, confidence: 'medium' } }));
        await render(hbs`<Order::Details::Tracking @resource={{this.order}} />`);
        assert.dom('.tracking-intelligence-alert').exists({ count: 1 }, 'a dispatched lifecycle suppresses the operator warning');
        assert.dom('.tracking-intelligence-cell__sub--warn').hasText('Reported ETA may not reflect the latest tracking signal.');

        this.set('order', buildOrder({ tracker_data: { stops: undefined, active_stop: null, progress: {}, route: { distance_m: 10 } } }));
        await render(hbs`<Order::Details::Tracking @resource={{this.order}} />`);
        assert.dom('.tracking-intelligence-destination__label').hasText('NOW HEADING TO', 'no stops array at all');
        assert.dom(findAll('.tracking-intelligence-distance__bar span')[0]).hasAttribute('style', 'width: 2%;');

        const stops = [
            { uuid: 'a', type: 'pickup' },
            { uuid: 'b', type: 'dropoff' },
        ];
        this.set('order', buildOrder({ tracker_data: { stops, active_stop: null } }));
        await render(hbs`<Order::Details::Tracking @resource={{this.order}} />`);
        assert.dom('.tracking-intelligence-destination__label').hasText('NOW HEADING TO');
        assert.dom('.tracking-intelligence-destination__marker').hasText('•');
    });
});
