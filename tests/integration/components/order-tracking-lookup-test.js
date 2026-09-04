import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, fillIn, findAll, render, settled, waitUntil } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';
import EmberObject from '@ember/object';
import { initialize as registerTrackingMarker } from '@fleetbase/fleetops-engine/instance-initializers/register-leaflet-tracking-marker';

// The routing control fetches its route through `@mapbox/corslite`, which uses the global
// XMLHttpRequest against the console's OSRM host. The suite answers those requests itself.
class FakeXHR {
    static sent = [];
    withCredentials = false;
    onload = null;
    onerror = null;
    readyState = 0;
    status = 0;
    responseText = '';

    open(method, url) {
        this.method = method;
        this.url = url;
    }

    setRequestHeader() {}

    send() {
        FakeXHR.sent.push(this);
    }

    abort() {
        this.aborted = true;
    }

    respond(status, body) {
        this.status = status;
        this.readyState = 4;
        this.responseText = typeof body === 'string' ? body : JSON.stringify(body);
        this.onload?.();
    }
}

const OSRM_OK = {
    code: 'Ok',
    waypoints: [
        { location: [-80.84, 35.22], hint: 'a' },
        { location: [-80.8, 35.25], hint: 'b' },
    ],
    routes: [{ distance: 5000, duration: 600, geometry: '_p~iF~ps|U_ulLnnqC', legs: [{ summary: 'Main St', steps: [] }] }],
};

function place(attrs) {
    return EmberObject.create({ hasInvalidCoordinates: false, ...attrs });
}

function makeOrder(overrides = {}) {
    const driver = EmberObject.create({
        id: 'driver_1',
        public_id: 'driver_abc',
        name: 'Sam Driver',
        online: true,
        heading: 90,
        coordinates: [35.22, -80.84],
        positionString: '35.22, -80.84',
        vehicle_avatar: '/assets/images/truck.svg',
    });

    return EmberObject.create({
        tracking: 'FLE1',
        public_id: 'order_1',
        status: 'started',
        tracking_number: { tracking_number: 'FLE1' },
        has_driver_assigned: true,
        driver_assigned: driver,
        tracker_data: {
            driver: { location: { coordinates: [-80.84, 35.22] } },
            progress: { percentage: 40, completed_stops: 1 },
            eta: { active_stop_seconds: 900, completion_at: '2026-05-12T04:49:26Z' },
            active_stop: { address: 'Active Stop Address' },
            next_stop: { address: 'Next Stop Address' },
        },
        tracking_statuses: [{ status: 'Dispatched', createdAtShortWithTime: '12 May 03:49', details: 'Left the depot' }],
        isMultipleDropoffOrder: false,
        payload: EmberObject.create({
            pickup: place({ latitude: 35.22, longitude: -80.84, street1: 'Pickup' }),
            dropoff: place({ latitude: 35.25, longitude: -80.8, street1: 'Dropoff' }),
            waypoints: [place({ latitude: 35.23, longitude: -80.82, street1: 'Waypoint' })],
            entities: [{ name: 'Parcel', description: 'Books', tracking: 'ENT1', price: 1500, currency: 'USD' }, { photo_url: '/x.png' }],
            entitiesByDestination: [],
        }),
        ...overrides,
    });
}

module('Integration | Component | order-tracking-lookup', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        const test = this;
        const calls = (this.calls = []);
        this.params = {};
        this.fetchFails = false;
        this.order = makeOrder();
        FakeXHR.sent = [];
        this.realXHR = window.XMLHttpRequest;
        window.XMLHttpRequest = FakeXHR;
        // The engine registers its tracking marker with ember-leaflet when it boots; the dummy app never boots it.
        registerTrackingMarker(this.owner);
        this.realConsoleError = console.error;
        console.error = (...args) => calls.push(['console.error', ...args.map((arg) => (arg && arg.error ? `${arg.error.status} ${arg.error.message}` : String(arg)))]);

        this.owner.register(
            'service:url-search-params',
            class extends Service {
                get(key) {
                    return test.params[key];
                }

                addParamToCurrentUrl(key, value) {
                    calls.push(['addParam', key, value]);
                }

                removeParamFromCurrentUrl(key) {
                    calls.push(['removeParam', key]);
                }
            }
        );
        this.owner.register(
            'service:fetch',
            class extends Service {
                async get(url, query, options) {
                    calls.push(['get', url, query, options]);
                    if (test.fetchFails) {
                        throw new Error('not found');
                    }
                    return test.order;
                }
            }
        );
        this.owner.register(
            'service:notifications',
            class extends Service {
                serverError(error) {
                    calls.push(['serverError', error.message]);
                }
            }
        );
        this.tracked = [];
        this.engineServices = {
            location: {
                async getUserLocation() {
                    calls.push(['getUserLocation']);
                    return { latitude: 1.35, longitude: 103.82 };
                },
            },
            movementTracker: {
                registerTrackingMarker: () => calls.push(['registerTrackingMarker']),
                track: (driver) => test.tracked.push(driver),
            },
        };
        const universe = this.owner.lookup('service:universe');
        universe.getServiceFromEngine = (engine, name) => {
            calls.push(['engineService', engine, name]);
            return test.engineServices[name];
        };
        // The component resolves `router:main` itself (see its eslint-disable); the test intercepts that same instance.
        // eslint-disable-next-line ember/no-private-routing-service
        this.owner.lookup('router:main').transitionTo = (route) => calls.push(['transitionTo', route]);
    });

    hooks.afterEach(function () {
        window.XMLHttpRequest = this.realXHR;
        console.error = this.realConsoleError;
    });

    test('a tracking number looks the order up, draws its route and can be looked up again', async function (assert) {
        await render(hbs`<OrderTrackingLookup />`);

        assert.dom('form').exists();
        assert.deepEqual(
            this.calls.filter((call) => call[0] === 'engineService'),
            [
                ['engineService', '@fleetbase/fleetops-engine', 'movementTracker'],
                ['engineService', '@fleetbase/fleetops-engine', 'location'],
            ]
        );
        assert.ok(this.calls.some((call) => call[0] === 'registerTrackingMarker'));
        assert.ok(findAll('button').find((button) => /Lookup Order/.test(button.textContent)).disabled, 'the lookup waits for a tracking number');

        await fillIn('input', 'FLE1');
        await click(findAll('button').find((button) => /Lookup Order/.test(button.textContent)));

        assert.deepEqual(
            this.calls.find((call) => call[0] === 'get'),
            ['get', 'fleet-ops/lookup', { tracking: 'FLE1' }, { normalizeToEmberData: true, normalizeModelType: 'order' }]
        );
        assert.deepEqual(
            this.calls.find((call) => call[0] === 'addParam'),
            ['addParam', 'order', 'FLE1']
        );
        assert.dom('form').doesNotExist();
        assert.dom().includesText('Driver Assigned');
        assert.dom().includesText('15m');
        assert.dom().includesText('Active Stop Address');
        assert.dom().includesText('Next Stop Address');
        assert.dom().includesText('Dispatched');
        assert.dom().includesText('Left the depot');
        assert.dom().includesText('Parcel');
        assert.dom().includesText('Books');
        assert.dom().includesText('$15.00');
        assert.dom().includesText('Item 2');
        assert.dom().includesText('No description provided.');
        assert.dom('.leaflet-container').exists('the driver location readies the map');
        assert.dom('.leaflet-marker-icon').exists('the driver marker is drawn');
        assert.strictEqual(this.tracked[0], this.order.driver_assigned, 'the marker hands the driver to the movement tracker');
        assert.ok(this.order.driver_assigned._layer, 'the marker layer is attached to the driver');

        await waitUntil(() => FakeXHR.sent.length === 1);
        assert.ok(FakeXHR.sent[0].url.startsWith('https://router.project-osrm.org/route/v1/driving/'), 'the route is requested from the console OSRM host');
        FakeXHR.sent[0].respond(200, OSRM_OK);
        await settled();
        assert.deepEqual(
            this.calls.filter((call) => call[0] === 'console.error'),
            [],
            'the canned route parses cleanly'
        );
        assert.dom('.leaflet-overlay-pane path').exists('the found route is drawn');

        await click(findAll('button').find((button) => /View Route/i.test(button.getAttribute('title') || '') || button.querySelector('svg[data-icon="route"]')));
        await click(findAll('button').find((button) => button.querySelector('svg[data-icon="truck"]')));

        await click(findAll('button').find((button) => /Lookup another order/.test(button.textContent)));
        assert.deepEqual(this.calls.at(-1), ['removeParam', 'order']);
        assert.dom('form').exists();

        await fillIn('input', 'FLE1');
        await click(findAll('button').find((button) => /Lookup Order/.test(button.textContent)));
        await waitUntil(() => FakeXHR.sent.length === 2);
        assert.dom('.leaflet-container').exists('the second lookup re-mounts the map and replaces the route control');
        FakeXHR.sent[1].respond(500, 'boom');
        await settled();
    });

    test('an order in the url is looked up on construction, falling back to the user location for the map', async function (assert) {
        this.params.order = 'FLE1';
        this.order = makeOrder({
            has_driver_assigned: false,
            driver_assigned: null,
            tracker_data: { driver: {}, progress: {}, eta: {}, active_stop: null, next_stop: null },
            tracking_statuses: [],
            payload: EmberObject.create({
                pickup: place({ latitude: 35.22, longitude: -80.84 }),
                dropoff: place({ latitude: 0, longitude: 0 }),
                waypoints: [place({ latitude: 1, longitude: 2, hasInvalidCoordinates: true })],
                entities: [],
                entitiesByDestination: [],
            }),
        });

        await render(hbs`<OrderTrackingLookup />`);

        assert.dom('form').doesNotExist();
        assert.dom().includesText('No Driver Assigned');
        assert.dom().includesText('None');
        assert.dom('.leaflet-container').exists('the user location readies the map');
        assert.dom('.leaflet-marker-icon').doesNotExist();
        assert.ok(findAll('button').find((button) => button.querySelector('svg[data-icon="truck"]')).disabled, 'locate driver is disabled without a driver');
        assert.strictEqual(FakeXHR.sent.length, 0, 'a single routable point requests no route');

        await click(findAll('button').find((button) => button.querySelector('svg[data-icon="route"]')));
        await click(findAll('button').find((button) => /Back to Console/.test(button.textContent)));
        assert.deepEqual(this.calls.at(-1), ['transitionTo', 'console']);
    });

    test('a failed lookup is reported and multi-drop orders group their items', async function (assert) {
        this.fetchFails = true;
        await render(hbs`<OrderTrackingLookup />`);
        await fillIn('input', 'NOPE');
        await click(findAll('button').find((button) => /Lookup Order/.test(button.textContent)));
        assert.deepEqual(this.calls.at(-1), ['serverError', 'not found']);
        assert.dom('form').exists();

        this.fetchFails = false;
        this.order = makeOrder({
            isMultipleDropoffOrder: true,
            payload: EmberObject.create({
                pickup: place({ latitude: 35.22, longitude: -80.84 }),
                dropoff: null,
                waypoints: [],
                entities: [],
                entitiesByDestination: [
                    { waypoint: place({ street1: 'Stop A', tracking: 'WP1', status_code: 'completed' }), entities: [{ name: 'Crate', tracking: 'ENT9' }] },
                    { waypoint: place({ street1: 'Stop B' }), entities: [] },
                ],
            }),
        });
        await fillIn('input', 'FLE2');
        await click(findAll('button').find((button) => /Lookup Order/.test(button.textContent)));
        assert.dom().includesText('Stop A');
        assert.dom().includesText('WP1');
        assert.dom().includesText('Crate');
        assert.dom().includesText('ENT9');
        assert.dom().includesText('Stop B');
        assert.dom().doesNotIncludeText('None');
        assert.strictEqual(FakeXHR.sent.length, 0, 'a lone pickup requests no route');
    });
    test('the map waits for the user location when the tracker carries no driver location', async function (assert) {
        let resolveLocation;
        this.engineServices.location.getUserLocation = () => new Promise((resolve) => (resolveLocation = resolve));
        this.params.order = 'FLE1';
        this.order = makeOrder({
            has_driver_assigned: true,
            driver_assigned: null,
            tracker_data: { driver: {}, progress: {}, eta: {}, active_stop: null, next_stop: null },
            payload: EmberObject.create({
                pickup: place({ latitude: 35.22, longitude: -80.84 }),
                dropoff: place({ latitude: 35.25, longitude: -80.8 }),
                waypoints: [],
                entities: [],
                entitiesByDestination: [],
            }),
        });

        await render(hbs`<OrderTrackingLookup />`);

        assert.dom('form').doesNotExist();
        assert.dom('.leaflet-container').doesNotExist('no location yet, no map');

        resolveLocation({ latitude: 1.35, longitude: 103.82 });
        await settled();
        assert.dom('.leaflet-container').exists();
        assert.dom('.leaflet-marker-icon[src*="marker-icon"]').exists({ count: 2 }, 'the route control draws its two waypoint markers');
        assert.dom('.leaflet-marker-icon[src*="truck"]').doesNotExist('no driver, no tracking marker');

        const locate = findAll('button').find((button) => button.querySelector('svg[data-icon="truck"]'));
        assert.notOk(locate.disabled, 'the order claims a driver');
        await click(locate);
        assert.dom('.leaflet-container').exists('locating a missing driver is a no-op');
        await click(findAll('button').find((button) => button.querySelector('svg[data-icon="route"]')));

        await waitUntil(() => FakeXHR.sent.length === 1);
        FakeXHR.sent[0].respond(200, OSRM_OK);
        await settled();
        assert.dom('.leaflet-overlay-pane path').exists();
    });
});
