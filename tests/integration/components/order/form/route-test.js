import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, findAll, render } from '@ember/test-helpers';
import Service from '@ember/service';
import { hbs } from 'ember-cli-htmlbars';
import Component from '@glimmer/component';
import { action } from '@ember/object';
import { setComponentTemplate } from '@ember/component';
import stubFormInputs, { AbilitiesStub, makeRecord } from 'dummy/tests/helpers/stub-form-inputs';
import registerTemplateOnly from 'dummy/tests/helpers/register-template-only';

function waypointRows() {
    return findAll('[data-test-waypoint-row]');
}

module('Integration | Component | order/form/route', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        const calls = (this.calls = []);
        const test = this;
        this.owner.register('service:abilities', AbilitiesStub);
        stubFormInputs(this.owner);
        // `preparePlaceForSave` reads `place.constructor.eachAttribute` for a persisted place, so
        // the place select has to yield a real record rather than a plain object. The class is
        // built per test because `setComponentTemplate` refuses a second call on the same class.
        class ModelSelectStub extends Component {
            @action select() {
                this.args.onChange(test.nextSelection);
            }
        }
        this.owner.register(
            'component:model-select',
            setComponentTemplate(hbs`<button type="button" data-test-model-select={{@modelName}} {{on "click" this.select}}>{{@placeholder}}</button>`, ModelSelectStub)
        );
        // `route-optimization` builds its engine registry against
        // `universe.getApplicationInstance()`, which the host sets during boot; a rendering test
        // has no boot, so point it at the test container.
        this.owner.lookup('service:universe').applicationInstance = this.owner;

        this.routingControl = { id: 'routing_control_1' };
        this.optimizeResult = null;
        this.optimizeError = null;

        this.owner.register(
            'service:map-manager',
            class extends Service {
                positionWaypoints(coordinates, options) {
                    calls.push(['positionWaypoints', coordinates, options.singlePointZoom]);
                }

                async replaceRoutingControl(coordinates, existing, options) {
                    calls.push(['replaceRoutingControl', coordinates.length, existing, options.fitOptions.maxZoom ?? null]);
                    test.lastRoutingOptions = options;
                    return test.routingControl;
                }

                removeRoutingControl(control) {
                    calls.push(['removeRoutingControl', control?.id ?? control]);
                }
            }
        );
        this.owner.register(
            'service:route-engine',
            class extends Service {
                getDisplayEngine(name) {
                    return `display:${name}`;
                }

                getOptimizationEngine(name) {
                    return `optimize:${name}`;
                }
            }
        );
        this.owner.register(
            'service:route-optimization',
            class extends Service {
                async optimize(service, params) {
                    calls.push(['optimize', service, params.context, params.coordinates]);
                    if (test.optimizeError) {
                        throw test.optimizeError;
                    }
                    return test.optimizeResult;
                }
            }
        );
        this.owner.register(
            'service:place-actions',
            class extends Service {
                modal = { edit: (place) => calls.push(['editPlace', place?.id ?? place]) };
            }
        );
        this.owner.register(
            'service:order-creation',
            class extends Service {
                requestServiceQuoteRefresh(reason, resource) {
                    calls.push(['refresh', reason, resource?.id]);
                }
            }
        );
        this.owner.register(
            'service:notifications',
            class extends Service {
                error(message) {
                    calls.push(['error', message]);
                }
            }
        );

        this.store = this.owner.lookup('service:store');
        this.makeOrder = (attributes = {}) => {
            const payload = this.store.createRecord('payload');
            return makeRecord('order', { id: 'order_1', public_id: 'ORD-1', customer: null, driver_assigned: null, payload, ...attributes });
        };
        // `latitude`/`longitude` are computed from `location` on the Place model, so a fixture
        // has to set the point itself; coordinates are [longitude, latitude].
        this.makePlace = ({ latitude = 30.27, longitude = -97.74, ...attributes } = {}) =>
            this.store.createRecord('place', { public_id: 'place_1', location: { type: 'Point', coordinates: [longitude, latitude] }, ...attributes });
    });

    test('reasons reported to the quote service name the mutation that caused them', async function (assert) {
        this.set('resource', this.makeOrder());

        await render(hbs`<Order::Form::Route @resource={{this.resource}} />`);
        this.calls.length = 0;

        await click('[role="checkbox"]');
        await click('[data-test-waypoint-add]');
        assert.deepEqual(
            this.calls.filter(([kind]) => kind === 'refresh').map(([, reason]) => reason),
            ['route.waypoint.added', 'route.waypoints.toggled', 'route.waypoint.added'],
            'toggling adds the first waypoint before reporting the toggle'
        );
        assert.strictEqual(waypointRows().length, 2);
    });

    test('a waypoint row edits, re-places and removes its stop', async function (assert) {
        this.set('resource', this.makeOrder());
        const place = this.makePlace({ street1: '1 Main St', city: 'Austin' });

        await render(hbs`<Order::Form::Route @resource={{this.resource}} />`);
        await click('[role="checkbox"]');
        await click('[data-test-waypoint-add]');
        this.calls.length = 0;

        // Assigning a place through the row's model select carries the address onto the waypoint.
        this.nextSelection = place;
        await click(waypointRows()[0].querySelector('[data-test-model-select="place"]'));
        assert.deepEqual(this.calls.at(-1), ['refresh', 'route.waypoint.place.changed', 'order_1']);

        // The first row never renders a remove button ({{#unless (eq index 0)}}), and the edit
        // button only appears once the row has a place.
        const rowButtons = (row) => [...waypointRows()[row].querySelectorAll('.btn-wrapper button')];
        assert.strictEqual(rowButtons(0).length, 1, 'the first row offers edit only');
        assert.strictEqual(rowButtons(1).length, 1, 'a placeless later row offers remove only');

        this.calls.length = 0;
        await click(rowButtons(0)[0]);
        assert.deepEqual(this.calls, [['editPlace', place]], 'the first row edits its place');

        this.calls.length = 0;
        await click(rowButtons(1)[0]);
        assert.strictEqual(waypointRows().length, 1, 'the later row is removed');
        assert.deepEqual(this.calls.at(-1), ['refresh', 'route.waypoint.removed', 'order_1']);
    });

    test('single-route mode assigns and clears the payload places', async function (assert) {
        this.set('resource', this.makeOrder());

        await render(hbs`<Order::Form::Route @resource={{this.resource}} />`);
        this.calls.length = 0;

        this.nextSelection = this.makePlace({ public_id: 'place_pickup' });
        await click(findAll('[data-test-model-select="place"]')[0]);
        assert.strictEqual(this.resource.payload.pickup, this.nextSelection);
        assert.deepEqual(this.calls.at(-1), ['refresh', 'route.pickup.changed', 'order_1']);

        this.calls.length = 0;
        this.nextSelection = this.makePlace({ public_id: 'place_dropoff', latitude: 32.78, longitude: -96.8 });
        await click(findAll('[data-test-model-select="place"]')[1]);
        assert.strictEqual(this.resource.payload.dropoff, this.nextSelection);
        assert.deepEqual(this.calls.at(-1), ['refresh', 'route.dropoff.changed', 'order_1']);
    });

    test('switching back to a single route promotes the first two waypoints', async function (assert) {
        this.set('resource', this.makeOrder());
        const pickup = this.makePlace({ public_id: 'place_pickup' });
        const dropoff = this.makePlace({ public_id: 'place_dropoff', latitude: 32.78, longitude: -96.8 });
        this.resource.payload.setProperties({ pickup, dropoff });

        await render(hbs`<Order::Form::Route @resource={{this.resource}} />`);

        await click('[role="checkbox"]');
        assert.strictEqual(waypointRows().length, 2, 'the pickup and dropoff become the first two waypoints');
        assert.strictEqual(this.resource.payload.pickup, null);
        assert.strictEqual(this.resource.payload.dropoff, null);

        this.calls.length = 0;
        await click('[role="checkbox"]');
        assert.strictEqual(waypointRows().length, 0);
        assert.strictEqual(this.resource.payload.pickup, pickup, 'the first waypoint returns to pickup');
        assert.strictEqual(this.resource.payload.dropoff, dropoff, 'the second returns to dropoff');
        assert.deepEqual(
            this.calls
                .filter(([kind]) => kind === 'refresh')
                .map(([, reason]) => reason)
                .at(-1),
            'route.waypoints.toggled'
        );
    });

    test('the route preview reflects how many points the payload has', async function (assert) {
        this.set('resource', this.makeOrder());

        await render(hbs`<Order::Form::Route @resource={{this.resource}} />`);
        this.calls.length = 0;

        // No coordinates: any existing control is torn down rather than replaced.
        await click('[role="checkbox"]');
        const previews = this.calls.filter(([kind]) => kind === 'replaceRoutingControl' || kind === 'removeRoutingControl');
        assert.deepEqual(previews, [], 'a waypoint with no place contributes no coordinates');

        this.resource.payload.pickup = this.makePlace({ public_id: 'place_pickup' });
        this.calls.length = 0;
        await click('[data-test-waypoint-add]');
        assert.deepEqual(this.calls.at(0), ['replaceRoutingControl', 1, undefined, null], 'the first preview has no control to replace and no max zoom');

        this.resource.payload.dropoff = this.makePlace({ public_id: 'place_dropoff', latitude: 32.78, longitude: -96.8 });
        this.calls.length = 0;
        await click('[data-test-waypoint-add]');
        assert.deepEqual(this.calls.at(0), ['replaceRoutingControl', 2, this.routingControl, 13], 'two points cap the zoom at 13');

        this.resource.payload.return = this.makePlace({ public_id: 'place_return', latitude: 29.76, longitude: -95.37 });
        this.calls.length = 0;
        await click('[data-test-waypoint-add]');
        assert.deepEqual(this.calls.at(0), ['replaceRoutingControl', 3, this.routingControl, 12], 'three or more cap it at 12');

        const options = this.lastRoutingOptions;
        assert.strictEqual(options.engine, 'display:osrm');
        assert.strictEqual(options.orderId, 'ORD-1');
        assert.strictEqual(options.status, 'created', 'an order with no status previews as created');
        assert.ok(options.createMarker({}, 0), 'each waypoint gets a marker presentation');
        assert.true(options.removeOptions.filter({ tag: undefined }), 'the filter matches the assigned driver tag');
    });

    test('optimizing sorts the waypoints, stores the route and reports failures', async function (assert) {
        this.set('resource', this.makeOrder());
        this.resource.payload.pickup = this.makePlace({ public_id: 'place_pickup' });
        this.resource.payload.dropoff = this.makePlace({ public_id: 'place_dropoff', latitude: 32.78, longitude: -96.8 });

        await render(hbs`<Order::Form::Route @resource={{this.resource}} />`);
        await click('[role="checkbox"]');
        // The Optimize button stays disabled below three waypoints.
        await click('[data-test-waypoint-add]');
        await click('[data-test-waypoint-add]');

        const sorted = this.resource.payload.waypoints.slice().reverse();
        this.optimizeResult = {
            sortedWaypoints: sorted,
            route: [[30.27, -97.74]],
            trip: { distance: 1200, duration: 900 },
            result: { waypoints: sorted },
        };
        this.calls.length = 0;

        await click(findAll('button').find((button) => /Optimize/i.test(button.textContent)));
        const [service, context, coordinates] = this.calls.find(([kind]) => kind === 'optimize').slice(1);
        assert.strictEqual(service, 'optimize:osrm');
        assert.strictEqual(context, 'create_order');
        assert.deepEqual(coordinates[0], [-97.74, 30.27], 'coordinates are sent longitude first');
        assert.true(this.resource.optimized);
        assert.strictEqual(this.resource.route.summary.totalDistance, 1200);
        assert.strictEqual(this.resource.route.summary.totalTime, 900);
        assert.strictEqual(this.resource.route.engine, 'osrm');
        assert.deepEqual(
            this.calls.filter(([kind]) => kind === 'refresh').map(([, reason]) => reason),
            ['route.changed', 'route.optimized']
        );

        this.optimizeResult = { sortedWaypoints: sorted, result: { waypoints: sorted } };
        this.calls.length = 0;
        await click(findAll('button').find((button) => /Optimize/i.test(button.textContent)));
        assert.notOk(
            this.calls.some(([kind, reason]) => kind === 'refresh' && reason === 'route.changed'),
            'a result with no route leaves the stored route alone'
        );

        this.optimizeError = new Error('engine unavailable');
        this.calls.length = 0;
        await click(findAll('button').find((button) => /Optimize/i.test(button.textContent)));
        assert.deepEqual(this.calls.at(-1), ['error', 'Route optimization failed, check route entry and try again.'], 'a failure reports the translated message');
    });

    test('the engine-select button optimizes with the chosen service and surfaces its error', async function (assert) {
        this.owner.lookup('service:route-optimization').availableEngines = ['vroom'];
        registerTemplateOnly(this.owner, 'route-optimization-engine-select-button', hbs`<button type="button" data-test-engine-select {{on "click" (fn @onClick "vroom")}}></button>`);
        this.set('resource', this.makeOrder());
        this.resource.payload.pickup = this.makePlace({ public_id: 'place_pickup' });

        await render(hbs`<Order::Form::Route @resource={{this.resource}} />`);
        await click('[role="checkbox"]');

        const sorted = this.resource.payload.waypoints.slice().reverse();
        this.optimizeResult = {
            sortedWaypoints: sorted,
            route: [[30.27, -97.74]],
            trip: { distance: 4200, duration: 1800 },
            result: { waypoints: sorted },
            engine: 'vroom',
        };
        this.calls.length = 0;
        await click('[data-test-engine-select]');
        assert.strictEqual(this.calls.find(([kind]) => kind === 'optimize')[1], 'vroom', 'the chosen engine is used');
        assert.true(this.resource.optimized);
        assert.strictEqual(this.resource.route.engine, 'vroom', 'the route records the engine that produced it');
        assert.strictEqual(this.resource.route.summary.totalDistance, 4200);

        this.optimizeResult = null;
        this.optimizeError = new Error('vroom refused the trip');
        this.calls.length = 0;
        await click('[data-test-engine-select]');
        assert.deepEqual(this.calls.at(-1), ['error', 'vroom refused the trip'], 'the engine error reaches the user verbatim');
    });

    test('reordering the waypoints previews the new order, and a no-op drag does nothing', async function (assert) {
        // DragSortList only calls @dragEndAction from a real drag; the stand-in yields the same
        // block and exposes a button that hands the action a drag payload.
        const test = this;
        class DragSortListStub extends Component {
            @action end() {
                this.args.dragEndAction(test.nextSort(this.args.items));
            }
        }
        this.owner.register(
            'component:drag-sort-list',
            setComponentTemplate(
                hbs`<button type="button" data-test-drag-end {{on "click" this.end}}></button>{{#each @items as |item index|}}{{yield item index}}{{/each}}`,
                DragSortListStub
            )
        );
        this.set('resource', this.makeOrder());

        await render(hbs`<Order::Form::Route @resource={{this.resource}} />`);
        await click('[role="checkbox"]');
        await click('[data-test-waypoint-add]');
        const [first, second] = this.resource.payload.waypoints.slice();

        this.nextSort = (items) => ({ sourceList: items, sourceIndex: 0, targetList: items, targetIndex: 0 });
        this.calls.length = 0;
        await click('[data-test-drag-end]');
        assert.deepEqual(this.calls, [], 'dropping a waypoint where it started changes nothing');
        assert.deepEqual(this.resource.payload.waypoints.slice(), [first, second]);

        this.nextSort = (items) => ({ sourceList: items, sourceIndex: 0, targetList: items, targetIndex: 1 });
        await click('[data-test-drag-end]');
        assert.deepEqual(this.resource.payload.waypoints.slice(), [second, first], 'the waypoint moves');
        assert.deepEqual(this.calls.at(-1), ['refresh', 'route.waypoints.reordered', 'order_1']);
    });

    test('a waypoint carries the customer picked for it', async function (assert) {
        this.set('resource', this.makeOrder());

        await render(hbs`<Order::Form::Route @resource={{this.resource}} />`);
        await click('[role="checkbox"]');

        const customer = this.store.createRecord('customer', { name: 'Acme', customer_type: 'contact' });
        this.nextSelection = customer;
        await click(waypointRows()[0].querySelector('[data-test-model-select="customer"]'));

        const waypoint = this.resource.payload.waypoints[0];
        assert.strictEqual(waypoint.customer, customer);
        assert.strictEqual(waypoint.customer_type, 'fleet-ops:contact', 'the type is namespaced');
    });

    test('the preview colours the route by the order status', async function (assert) {
        this.set('resource', this.makeOrder({ status: 'completed' }));
        this.resource.payload.pickup = this.makePlace({ public_id: 'place_pickup' });

        await render(hbs`<Order::Form::Route @resource={{this.resource}} />`);
        await click('[role="checkbox"]');

        assert.strictEqual(this.lastRoutingOptions.status, 'completed', 'the order status drives the preview');
        assert.ok(this.lastRoutingOptions.polylineOptions.color, 'a status colour is chosen');
        assert.ok(this.lastRoutingOptions.polylineOptions.weight > 0, 'the polyline takes a weight');
        assert.ok(this.lastRoutingOptions.polylineOptions.opacity > 0);
    });

    test('an optimized trip reports totals under either naming, and none at all', async function (assert) {
        this.set('resource', this.makeOrder());
        this.resource.payload.pickup = this.makePlace({ public_id: 'place_pickup' });
        this.resource.payload.dropoff = this.makePlace({ public_id: 'place_dropoff', latitude: 32.78, longitude: -96.8 });

        await render(hbs`<Order::Form::Route @resource={{this.resource}} />`);
        await click('[role="checkbox"]');
        await click('[data-test-waypoint-add]');

        const sorted = this.resource.payload.waypoints.slice();
        const optimize = async () => click(findAll('button').find((button) => /Optimize/i.test(button.textContent)));

        this.optimizeResult = { sortedWaypoints: sorted, route: [[30.27, -97.74]], trip: { totalDistance: 500, totalTime: 60 }, result: { waypoints: sorted } };
        await optimize();
        assert.strictEqual(this.resource.route.summary.totalDistance, 500, 'totalDistance is read when distance is absent');
        assert.strictEqual(this.resource.route.summary.totalTime, 60);

        this.optimizeResult = { sortedWaypoints: sorted, route: [[30.27, -97.74]], result: { waypoints: sorted }, engine: 'vroom' };
        await optimize();
        assert.strictEqual(this.resource.route.summary.totalDistance, 0, 'a trip-less result reports zero');
        assert.strictEqual(this.resource.route.summary.totalTime, 0);
        assert.strictEqual(this.resource.route.engine, 'vroom', 'the result names the engine that produced it');
    });

    test('an engine error with no message still reports the translated failure', async function (assert) {
        this.owner.lookup('service:route-optimization').availableEngines = ['vroom'];
        registerTemplateOnly(this.owner, 'route-optimization-engine-select-button', hbs`<button type="button" data-test-engine-select {{on "click" (fn @onClick "vroom")}}></button>`);
        this.set('resource', this.makeOrder());
        this.resource.payload.pickup = this.makePlace({ public_id: 'place_pickup' });

        await render(hbs`<Order::Form::Route @resource={{this.resource}} />`);
        await click('[role="checkbox"]');

        this.optimizeError = { name: 'EngineRejection' };
        this.calls.length = 0;
        await click('[data-test-engine-select]');
        assert.deepEqual(this.calls.at(-1), ['error', 'Route optimization failed, check route entry and try again.'], 'a message-less error falls back to the translation');
    });

    test('the preview identifies the order by public id, then id, then as new', async function (assert) {
        const previewWith = async (attributes) => {
            this.set('resource', this.makeOrder(attributes));
            this.resource.payload.pickup = this.makePlace({ public_id: 'place_pickup' });
            await render(hbs`<Order::Form::Route @resource={{this.resource}} />`);
            await click('[role="checkbox"]');
            return this.lastRoutingOptions.orderId;
        };

        assert.strictEqual(await previewWith({}), 'ORD-1', 'the public id names the route');
        assert.strictEqual(await previewWith({ public_id: undefined }), 'order_1', 'without one it falls back to the id');
        assert.strictEqual(await previewWith({ public_id: undefined, id: undefined }), 'new-order', 'an unsaved order is "new-order"');
    });

    test('adding a waypoint carries the order customer onto it', async function (assert) {
        const customer = this.store.createRecord('customer', { name: 'Acme', customer_type: 'contact' });
        this.set('resource', this.makeOrder({ customer }));

        await render(hbs`<Order::Form::Route @resource={{this.resource}} />`);
        await click('[role="checkbox"]');

        assert.strictEqual(this.resource.payload.waypoints[0].customer, customer, 'the order customer seeds the waypoint');
    });

    test('an assigned pickup and dropoff can be edited or cleared inline', async function (assert) {
        this.set('resource', this.makeOrder());
        const pickup = this.makePlace({ public_id: 'place_pickup' });
        const dropoff = this.makePlace({ public_id: 'place_dropoff', latitude: 32.78, longitude: -96.8 });
        this.resource.payload.setProperties({ pickup, dropoff });

        await render(hbs`<Order::Form::Route @resource={{this.resource}} />`);

        // Each assigned place renders an edit anchor followed by a clear anchor, inside the
        // input group whose label names it.
        const groupLinks = (label) => {
            const group = findAll('.input-group').find((element) => element.querySelector('label')?.textContent.trim() === label);
            return [...group.querySelectorAll('a')];
        };
        assert.strictEqual(groupLinks('Pickup').length, 2, 'the pickup offers edit and clear');
        assert.strictEqual(groupLinks('Dropoff').length, 2);

        this.calls.length = 0;
        for (const link of groupLinks('Pickup')) {
            await click(link);
        }
        assert.ok(
            this.calls.some(([kind]) => kind === 'editPlace'),
            'one pickup link opens the place modal'
        );
        assert.ok(
            this.calls.some(([kind, reason]) => kind === 'refresh' && reason === 'route.pickup.changed'),
            'the other clears the pickup'
        );
        assert.strictEqual(this.resource.payload.pickup, null);

        this.calls.length = 0;
        for (const link of groupLinks('Dropoff')) {
            await click(link);
        }
        assert.strictEqual(this.resource.payload.dropoff, null, 'the dropoff is cleared');
        assert.ok(this.calls.some(([kind, reason]) => kind === 'refresh' && reason === 'route.dropoff.changed'));
    });

    test('collapsing a route of unplaced stops leaves the payload places empty', async function (assert) {
        this.set('resource', this.makeOrder());

        await render(hbs`<Order::Form::Route @resource={{this.resource}} />`);
        await click('[role="checkbox"]');
        await click('[data-test-waypoint-add]');
        assert.strictEqual(waypointRows().length, 2, 'two stops, neither of them placed');

        this.calls.length = 0;
        await click('[role="checkbox"]');

        assert.strictEqual(waypointRows().length, 0, 'the stops are cleared');
        assert.strictEqual(this.resource.payload.pickup, null, 'an unplaced first stop promotes nothing to pickup');
        assert.strictEqual(this.resource.payload.dropoff, null, 'nor the second to dropoff');
        assert.deepEqual(
            this.calls.filter(([kind]) => kind === 'refresh').map(([, reason]) => reason),
            ['route.waypoints.cleared', 'route.waypoints.toggled'],
            'only the clear and the toggle are reported'
        );
    });

    test('the preview tags its routing control with the assigned driver', async function (assert) {
        this.set('resource', this.makeOrder({ driver_assigned: { id: 'driver_1' } }));
        this.resource.payload.pickup = this.makePlace({ public_id: 'place_pickup' });

        await render(hbs`<Order::Form::Route @resource={{this.resource}} />`);
        // Nothing previews on render; switching the route on is what draws it.
        await click('[role="checkbox"]');

        assert.strictEqual(this.lastRoutingOptions.tag, 'driver_1', 'the control is tagged with the driver it belongs to');

        // The map adapter runs this filter over the handles already on the map to decide which of
        // them this preview may replace.
        const { filter } = this.lastRoutingOptions.removeOptions;
        assert.true(filter({ tag: 'driver_1' }), "a handle for this order's driver is replaced");
        assert.false(filter({ tag: 'driver_2' }), "another driver's handle is left alone");
        assert.false(filter(undefined), 'and an absent handle matches nothing');

        // The adapter hands the drawn route back once the engine answers.
        this.calls.length = 0;
        this.lastRoutingOptions.onRouteFound({ coordinates: [[30.27, -97.74]], summary: { totalDistance: 900, totalTime: 300 } });
        assert.strictEqual(this.resource.route.summary.totalDistance, 900, 'the found route is stored on the order');
        assert.deepEqual(this.calls.at(-1), ['refresh', 'route.changed', 'order_1']);
    });
});
