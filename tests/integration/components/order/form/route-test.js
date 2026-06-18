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
});
