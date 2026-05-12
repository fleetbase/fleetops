import Service from '@ember/service';
import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | order/details/tracking', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:order-actions', class OrderActionsService extends Service {});
    });

    function buildOrder(overrides = {}) {
        return {
            tracking: 'FLE2177254646SG',
            public_id: 'order_test',
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
                },
                active_stop: {
                    address: '11807 Broadway Lane, Charlotte, 28273, United States',
                },
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

        assert.dom().containsText('Current ETA');
        assert.dom().containsText('Completion ETA');
        assert.dom().containsText('Remaining Distance');
        assert.dom().containsText('Current Destination');
        assert.dom().containsText('Route Progress');
        assert.dom().containsText('Live');
        assert.dom().containsText('Google Routes route');
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

        assert.dom().containsText('Stale');
        assert.dom().containsText('Driver location is stale');
    });

    test('it shows missing driver location as the clearest warning', async function (assert) {
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

        assert.dom().containsText('Missing');
        assert.dom().containsText('Driver location is missing');
    });
});
