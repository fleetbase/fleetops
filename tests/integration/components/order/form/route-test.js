import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, render, settled } from '@ember/test-helpers';
import { A } from '@ember/array';
import Service from '@ember/service';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | order/form/route', function (hooks) {
    setupRenderingTest(hooks);

    test('it marks pickup and dropoff as required in single-route mode', async function (assert) {
        this.set('resource', {
            facilitator: {
                isIntegratedVendor: false,
            },
            payload: {
                pickup: null,
                dropoff: null,
                return: null,
                waypoints: A([]),
            },
        });

        await render(hbs`<Order::Form::Route @resource={{this.resource}} />`);

        const requiredLabels = [...this.element.querySelectorAll('label.required')].map((label) => label.textContent.trim());

        assert.dom('label.required').exists({ count: 2 });
        assert.true(requiredLabels.includes('Pickup'));
        assert.true(requiredLabels.includes('Dropoff'));
    });

    test('it renders route-list style waypoint badges and required tabs for the first two waypoints', async function (assert) {
        this.set('resource', {
            customer: null,
            driver_assigned: null,
            id: 'test-order',
            facilitator: {
                isIntegratedVendor: false,
            },
            payload: {
                pickup: null,
                dropoff: null,
                return: null,
                waypoints: A([]),
                setProperties(properties) {
                    Object.assign(this, properties);
                },
            },
        });

        await render(hbs`<Order::Form::Route @resource={{this.resource}} />`);
        await click('[role="checkbox"]');
        this.resource.payload.waypoints.pushObject({ type: 'dropoff' });
        this.resource.payload.waypoints.pushObject({ type: 'dropoff' });
        await settled();

        assert.dom('[data-test-waypoint-row="1"] .fleetops-route-stop-badge').hasText('1');
        assert.dom('[data-test-waypoint-row="2"] .fleetops-route-stop-badge').hasText('2');
        assert.dom('[data-test-waypoint-row="3"] .fleetops-route-stop-badge').hasText('3');
        assert.dom('[data-test-waypoint-row="1"]').hasClass('fleetops-order-form-waypoint--required');
        assert.dom('[data-test-waypoint-row="2"]').hasClass('fleetops-order-form-waypoint--required');
        assert.dom('[data-test-waypoint-row="3"]').doesNotHaveClass('fleetops-order-form-waypoint--required');
        assert.dom('[data-test-required-waypoint-tab]').exists({ count: 2 });
    });

    test('route mutations request service quote refresh', async function (assert) {
        const requests = [];

        class OrderCreationStub extends Service {
            requestServiceQuoteRefresh(reason, resource) {
                requests.push({ reason, resource });
            }
        }

        this.owner.register('service:order-creation', OrderCreationStub);
        this.set('resource', {
            customer: null,
            driver_assigned: null,
            id: 'test-order',
            facilitator: {
                isIntegratedVendor: false,
            },
            payload: {
                pickup: null,
                dropoff: null,
                return: null,
                waypoints: A([]),
                setProperties(properties) {
                    Object.assign(this, properties);
                },
            },
        });

        await render(hbs`<Order::Form::Route @resource={{this.resource}} />`);
        await click('[role="checkbox"]');

        assert.true(
            requests.some((request) => request.reason === 'route.waypoints.toggled'),
            'requests refresh when waypoint mode changes'
        );
        assert.true(
            requests.every((request) => request.resource === this.resource),
            'requests refresh for the current order'
        );
    });
    test('editing a place saves it without refreshing the route', async function (assert) {
        const edits = [];
        const requests = [];

        class PlaceActionsStub extends Service {
            modal = {
                edit: (place, options, saveOptions) => edits.push({ place, options, saveOptions }),
            };
        }

        class OrderCreationStub extends Service {
            requestServiceQuoteRefresh(reason) {
                requests.push(reason);
            }
        }

        this.owner.register('service:place-actions', PlaceActionsStub);
        this.owner.register('service:order-creation', OrderCreationStub);
        const pickup = { id: 'place_pickup', address: '1 Pickup Street' };
        this.set('resource', {
            customer: null,
            driver_assigned: null,
            id: 'test-order',
            facilitator: {
                isIntegratedVendor: false,
            },
            payload: {
                pickup,
                dropoff: null,
                return: null,
                waypoints: A([]),
            },
        });

        await render(hbs`<Order::Form::Route @resource={{this.resource}} />`);
        await click('[data-test-edit-place="pickup"]');

        assert.strictEqual(edits.length, 1);
        assert.strictEqual(edits[0].place, pickup);
        // A refresh would re-enter the new order route and wipe the draft (fleetbase/fleetbase#651).
        assert.false(edits[0].saveOptions.refresh);

        edits[0].saveOptions.callback(pickup);
        await settled();

        assert.true(requests.includes('route.place.updated'), 'requests a new quote for the saved place');
    });
});
