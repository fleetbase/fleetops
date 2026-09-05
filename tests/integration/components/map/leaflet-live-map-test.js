import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render, settled } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';
import Component from '@glimmer/component';
import { setComponentTemplate } from '@ember/component';
import { inject as service } from '@ember/service';
import Evented from '@ember/object/evented';
import EmberObject from '@ember/object';
import { run } from '@ember/runloop';

/**
 * The live map has two provider branches — Leaflet and Google — behind the same component class,
 * chosen by `mapSettings.isGoogleMaps`. The Google branch renders a single child component, so
 * standing that child in gets at the class's own lifecycle, resource handlers and event plumbing
 * without needing ember-leaflet or a real map. The Leaflet branch needs a real Leaflet map and is
 * covered separately.
 */

/** The Google branch's child. Reports itself to the test bed and calls `@onLoad` on insert. */
class GoogleLiveMapStub extends Component {
    @service liveMapTestBed;

    constructor() {
        super(...arguments);
        this.liveMapTestBed.child = this;
        this.liveMapTestBed.args = this.args;
    }
}
setComponentTemplate(hbs`<div data-test-google-live-map></div>`, GoogleLiveMapStub);

module('Integration | Component | map/leaflet-live-map', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        const test = this;
        this.calls = [];

        this.owner.register('service:live-map-test-bed', class extends Service {});
        this.testBed = this.owner.lookup('service:live-map-test-bed');
        this.owner.register('component:map/google-live-map', GoogleLiveMapStub);

        // The provider switch, and the tile URL the Leaflet branch would use.
        this.isGoogleMaps = true;
        this.owner.register(
            'service:map-settings',
            class extends Service {
                get isGoogleMaps() {
                    return test.isGoogleMaps;
                }
                getLeafletTileUrl(theme) {
                    test.calls.push(['getLeafletTileUrl', theme]);
                    return `https://tiles.test/${theme}/{z}/{x}/{y}.png`;
                }
            }
        );

        // `universe` and `currentUser` are Evented — the constructor subscribes to both and
        // `willDestroy` unsubscribes, so the real Evented mixin is the honest stand-in.
        this.universeEvents = [];
        this.owner.register(
            'service:universe',
            class extends Service.extend(Evented) {
                trigger(name, ...rest) {
                    test.universeEvents.push([name, ...rest]);
                    return super.trigger(name, ...rest);
                }
            }
        );
        this.universe = this.owner.lookup('service:universe');

        this.companyId = 'company_1';
        this.owner.register(
            'service:current-user',
            class extends Service.extend(Evented) {
                get companyId() {
                    return test.companyId;
                }
            }
        );
        this.currentUser = this.owner.lookup('service:current-user');

        this.subscribed = [];
        this.owner.register(
            'service:geofence-event-bus',
            class extends Service {
                subscribe(companyId) {
                    test.subscribed.push(companyId);
                }
            }
        );

        this.coordinates = { latitude: 1.3, longitude: 103.8 };
        this.owner.register(
            'service:location',
            class extends Service {
                getLatitude() {
                    return test.coordinates.latitude;
                }
                getLongitude() {
                    return test.coordinates.longitude;
                }
            }
        );

        // The panel services each expose `panel.view(record, options)`.
        this.panelViews = [];
        const panelService = (name) =>
            class extends Service {
                panel = {
                    view: (record, options) => {
                        test.panelViews.push([name, record, options]);
                    },
                };
            };
        this.owner.register('service:driver-actions', panelService('driver'));
        this.owner.register('service:vehicle-actions', panelService('vehicle'));
        this.owner.register('service:place-actions', panelService('place'));

        this.serviceAreasLoaded = 0;
        this.owner.register(
            'service:service-area-actions',
            class extends Service {
                serviceAreas = [];
                loadAll = {
                    perform: () => {
                        test.serviceAreasLoaded += 1;
                        return Promise.resolve(test.serviceAreas ?? []);
                    },
                };
            }
        );
        this.owner.register('service:zone-actions', class extends Service {});

        this.tracked = [];
        this.owner.register(
            'service:movement-tracker',
            class extends Service {
                track(resource) {
                    test.tracked.push(resource);
                }
            }
        );

        this.drawControls = [];
        this.owner.register(
            'service:leaflet-map-manager',
            class extends Service {
                drawControl = null;
                drawControlFeatureGroup = null;
                setMap(map) {
                    test.drawControls.push(['setMap', map]);
                }
                setDrawControl(control) {
                    this.drawControl = control;
                    test.drawControls.push(['setDrawControl', control]);
                }
                setDrawControlFeatureGroup(group) {
                    this.drawControlFeatureGroup = group;
                    test.drawControls.push(['setDrawControlFeatureGroup', group]);
                }
            }
        );

        this.mapManagerCalls = [];
        this.mapBounds = null;
        this.owner.register(
            'service:map-manager',
            class extends Service {
                adapter = null;
                isReady = false;
                getBounds() {
                    test.mapManagerCalls.push(['getBounds']);
                    return test.mapBounds;
                }
                setLivemap(livemap) {
                    test.mapManagerCalls.push(['setLivemap', livemap]);
                }
                setActiveProvider(provider) {
                    test.mapManagerCalls.push(['setActiveProvider', provider]);
                }
                setMapInstance(map) {
                    test.mapManagerCalls.push(['setMapInstance', map]);
                }
            }
        );

        this.registeredLayers = [];
        this.owner.register(
            'service:leaflet-layer-visibility-manager',
            class extends Service {
                registerLayer(type, model, layer, options) {
                    test.registeredLayers.push({ type, model, layer, options });
                }
                setVisibility() {}
            }
        );
        this.contextMenus = [];
        this.owner.register(
            'service:leaflet-contextmenu-manager',
            class extends Service {
                contextMenuRegistry = new Map();
                createContextMenu(...args) {
                    test.contextMenus.push(['create', ...args]);
                }
                removeContextMenu(...args) {
                    test.contextMenus.push(['remove', ...args]);
                }
            }
        );
        this.owner.register('service:resource-context-panel', class extends Service {});
        // The map context menu binds `geofence.toggleDrawControl` while building its items, so an
        // empty stand-in throws there — inside `load`'s own try/catch, which swallows it and
        // leaves the map permanently unready with nothing in the output to say why.
        this.geofenceCalls = [];
        this.owner.register(
            'service:geofence',
            class extends Service {
                toggleDrawControl() {
                    test.geofenceCalls.push('toggleDrawControl');
                }
                createServiceArea() {
                    test.geofenceCalls.push('createServiceArea');
                }
                focusServiceArea(serviceArea) {
                    test.geofenceCalls.push(['focusServiceArea', serviceArea]);
                }
            }
        );
        this.owner.register(
            'service:fetch',
            class extends Service {
                get(path, params) {
                    test.calls.push(['get', path, params]);
                    return Promise.resolve(test.getResponses?.[path] ?? []);
                }
            }
        );
        this.owner.register(
            'service:abilities',
            class extends Service {
                denied = new Set();
                can(permission) {
                    return !this.denied.has(permission);
                }
                cannot(permission) {
                    test.calls.push(['cannot', permission]);
                    return !this.can(permission);
                }
            }
        );

        this.renderMap = () => render(hbs`<Map::LeafletLiveMap />`);
    });

    test('it renders the Google branch when the settings say so', async function (assert) {
        await this.renderMap();

        assert.dom('[data-test-google-live-map]').exists('the Google provider is used');
        assert.dom('.live-map-component').exists('inside the live-map wrapper');
    });

    test('the map starts where the location service says, at a sane zoom', async function (assert) {
        await this.renderMap();
        const { args } = this.testBed;

        assert.strictEqual(args.latitude, 1.3, 'the latitude comes from the location service');
        assert.strictEqual(args.longitude, 103.8);
        assert.strictEqual(args.zoom, 14, 'and the zoom defaults to 14 when none is given');
    });

    test('a zoom outside what Leaflet accepts is replaced with the default', async function (assert) {
        const zooms = [
            [10, 10, 'a zoom in range is kept'],
            [1, 1, 'the lowest Leaflet allows'],
            [20, 20, 'and the highest'],
            [0, 14, 'below the range falls back'],
            [21, 14, 'above it too'],
            [NaN, 14, 'so does a number that is not one'],
            ['12', 14, 'and a string, even a numeric one'],
            [undefined, 14, 'and nothing at all'],
        ];

        for (const [given, expected, message] of zooms) {
            this.zoom = given;
            await render(hbs`<Map::LeafletLiveMap @zoom={{this.zoom}} />`);
            assert.strictEqual(this.testBed.args.zoom, expected, message);
        }
    });

    test('the map subscribes to geofence events for the company it is looking at', async function (assert) {
        await this.renderMap();
        await settled();

        assert.deepEqual(this.subscribed, ['company_1'], 'the company channel is subscribed once the runloop settles');
    });

    test('a map rendered before the user has loaded subscribes when they arrive', async function (assert) {
        this.companyId = null;
        await this.renderMap();
        await settled();

        assert.deepEqual(this.subscribed, [], 'nothing is subscribed while there is no company');

        this.companyId = 'company_late';
        this.currentUser.trigger('user.loaded');
        await settled();

        assert.deepEqual(this.subscribed, ['company_late'], 'and the subscription happens as soon as the user lands');
    });

    test('the map follows the user when the location service moves them', async function (assert) {
        await this.renderMap();

        this.universe.trigger('user.located', { latitude: 51.5, longitude: -0.12 });
        await settled();

        assert.strictEqual(this.testBed.args.latitude, 51.5, 'the map re-centres on the new position');
        assert.strictEqual(this.testBed.args.longitude, -0.12);
    });

    test('a location update with nothing usable in it is ignored', async function (assert) {
        await this.renderMap();
        const before = [this.testBed.args.latitude, this.testBed.args.longitude];

        this.universe.trigger('user.located', {});
        this.universe.trigger('user.located');
        await settled();

        assert.deepEqual([this.testBed.args.latitude, this.testBed.args.longitude], before, 'the map stays where it was');
    });

    test('tearing the map down unsubscribes everything it subscribed to', async function (assert) {
        await this.renderMap();
        await settled();

        assert.true(this.universe.has('user.located'), 'the map is listening while it is on screen');
        assert.true(this.universe.has('fleet-ops.geofence.entered'));
        assert.true(this.universe.has('fleet-ops.geofence.exited'));

        await render(hbs`<span data-test-gone></span>`);

        assert.false(this.universe.has('user.located'), 'and stops when it goes away');
        assert.false(this.universe.has('fleet-ops.geofence.entered'));
        assert.false(this.universe.has('fleet-ops.geofence.exited'));
    });

    test('a map torn down before the user loaded stops listening for them too', async function (assert) {
        this.companyId = null;
        await this.renderMap();
        assert.true(this.currentUser.has('user.loaded'), 'it is waiting for the user');

        await render(hbs`<span data-test-gone></span>`);
        assert.false(this.currentUser.has('user.loaded'), 'and stops waiting when it goes away');
    });

    test('the spinner shows until the map reports itself ready', async function (assert) {
        await this.renderMap();
        assert.dom('.live-map-component .flex.w-full.h-full').exists('an unready map covers itself with a spinner');
    });

    /**
     * Load the Google map the way the child component does.
     *
     * The adapter needs its own `getBounds`: `load` reads
     * `this.mapManager.getBounds?.() ?? (this.map ? this.map.getBounds() : null)`, so whenever the
     * manager reports no bounds the `??` falls through to the map itself. An adapter without one
     * throws there, `load`'s own try/catch swallows it, and the map silently never loads — no
     * request, no `ready`, and nothing in the test output to say why.
     *
     * The `run()` wrapper keeps the task performed inside a runloop, as a real click would.
     */
    async function loadGoogleMap(test, adapter = EmberObject.create({ name: 'google-adapter', getBounds: () => test.mapBounds })) {
        run(() => test.testBed.args.onLoad({ target: adapter }));
        await settled();
        return adapter;
    }

    test('loading the Google map registers it as the active provider', async function (assert) {
        await this.renderMap();
        const adapter = await loadGoogleMap(this);

        const [, livemap] = this.mapManagerCalls.find(([name]) => name === 'setLivemap');
        assert.strictEqual(livemap.constructor.name, 'MapLeafletLiveMapComponent', 'the map manager is told which live map it belongs to');
        assert.ok(
            this.mapManagerCalls.some(([name, value]) => name === 'setActiveProvider' && value === 'google'),
            'and that Google is the provider in use'
        );
        assert.ok(
            this.universeEvents.some(([name, value]) => name === 'fleet-ops.live-map.loaded' && value === adapter),
            'the loaded event carries the adapter'
        );
        assert.strictEqual(this.universe.get('component:fleet-ops:live-map'), livemap, 'and the same component is published on the universe for other parts of the app');
    });

    test('loading pulls in every resource the map draws', async function (assert) {
        this.getResponses = {
            'fleet-ops/live/routes': [{ id: 'r_1' }],
            'fleet-ops/live/vehicles': [{ id: 'v_1' }],
            'fleet-ops/live/drivers': [{ id: 'd_1' }],
            'fleet-ops/live/places': [{ id: 'p_1' }],
        };
        this.serviceAreas = [{ id: 'sa_1' }];

        await this.renderMap();
        await loadGoogleMap(this);

        assert.deepEqual(
            this.calls.filter(([name]) => name === 'get').map(([, path]) => path),
            ['fleet-ops/live/routes', 'fleet-ops/live/vehicles', 'fleet-ops/live/drivers', 'fleet-ops/live/places'],
            'routes, vehicles, drivers and places are fetched'
        );
        assert.strictEqual(this.serviceAreasLoaded, 1, 'and the service areas come from their own action service');
        assert.deepEqual(this.testBed.args.drivers, [{ id: 'd_1' }], 'what comes back is handed to the map');
        assert.deepEqual(this.testBed.args.vehicles, [{ id: 'v_1' }]);
        assert.deepEqual(this.testBed.args.places, [{ id: 'p_1' }]);
    });

    test('resources the user may not list are not asked for', async function (assert) {
        const abilities = this.owner.lookup('service:abilities');
        abilities.denied.add('fleet-ops list drivers');
        abilities.denied.add('fleet-ops list places');

        await this.renderMap();
        await loadGoogleMap(this);

        assert.deepEqual(
            this.calls.filter(([name]) => name === 'get').map(([, path]) => path),
            ['fleet-ops/live/routes', 'fleet-ops/live/vehicles'],
            'the two the user cannot list are skipped entirely'
        );
    });

    test('a resource the server refuses does not stop the rest of the map loading', async function (assert) {
        this.owner.lookup('service:fetch').get = (path) => {
            this.calls.push(['get', path]);
            return path.endsWith('/drivers') ? Promise.reject(new Error('server said no')) : Promise.resolve([]);
        };

        await this.renderMap();
        await loadGoogleMap(this);

        assert.strictEqual(this.calls.filter(([name]) => name === 'get').length, 4, 'every resource is still attempted');
        assert.true(this.universe.get('component:fleet-ops:live-map').ready, 'and the map still finishes loading');
    });

    test('bounds are sent in whichever shape the provider reports them', async function (assert) {
        const shapes = [
            [
                [
                    [1, 100],
                    [2, 101],
                ],
                [1, 100, 2, 101],
                'a plain south-west / north-east pair',
            ],
            [{ getSouth: () => 1, getWest: () => 100, getNorth: () => 2, getEast: () => 101 }, [1, 100, 2, 101], "Leaflet's getSouth/getWest/getNorth/getEast"],
            [
                { getSouthWest: () => ({ lat: () => 1, lng: () => 100 }), getNorthEast: () => ({ lat: () => 2, lng: () => 101 }) },
                [1, 100, 2, 101],
                "Google's getSouthWest/getNorthEast with lat()/lng() methods",
            ],
        ];

        for (const [bounds, expected, message] of shapes) {
            this.calls = [];
            this.mapBounds = bounds;
            await this.renderMap();
            await loadGoogleMap(this);

            const [, , params] = this.calls.find(([name, path]) => name === 'get' && path.endsWith('/drivers'));
            assert.deepEqual(params.bounds, expected, message);
            assert.strictEqual(params.limit, 500, 'with the viewport resource limit');
        }
    });

    test('bounds that make no sense are left off the request', async function (assert) {
        const unusable = [
            [null, 'no bounds at all'],
            [
                [
                    [NaN, 100],
                    [2, 101],
                ],
                'a pair carrying something that is not a number',
            ],
            [{ getSouth: () => NaN, getWest: () => 100, getNorth: () => 2, getEast: () => 101 }, 'a Leaflet bounds that has not settled'],
            [{ nothing: true }, 'an object that is not a bounds at all'],
        ];

        for (const [bounds, message] of unusable) {
            this.calls = [];
            this.mapBounds = bounds;
            await this.renderMap();
            await loadGoogleMap(this);

            const [, , params] = this.calls.find(([name, path]) => name === 'get' && path.endsWith('/drivers'));
            assert.notOk(params.bounds, message);
            assert.strictEqual(params.limit, 500, 'but the limit still applies');
        }
    });

    // -------------------------------------------------------------------------
    // Resources arriving on the map
    // -------------------------------------------------------------------------

    test('a driver added to the map is tracked and given its layer', async function (assert) {
        await this.renderMap();
        const driver = EmberObject.create({ id: 'd_1' });
        const layer = EmberObject.create({});

        this.testBed.args.onDriverAdded(driver, { target: layer });

        assert.deepEqual(this.tracked, [driver], 'the movement tracker follows it from now on');
        assert.strictEqual(driver.leafletLayer, layer, 'and the record and its layer know about each other');
        assert.strictEqual(layer.record_id, 'd_1');
    });

    test('a vehicle added to the map is tracked too', async function (assert) {
        await this.renderMap();
        const vehicle = EmberObject.create({ id: 'v_1' });
        const layer = EmberObject.create({});

        this.testBed.args.onVehicleAdded(vehicle, { target: layer });

        assert.deepEqual(this.tracked, [vehicle]);
        assert.strictEqual(layer.record_id, 'v_1');
    });

    test('a place added to the map takes its layer but is not tracked', async function (assert) {
        await this.renderMap();
        const place = EmberObject.create({ id: 'p_1' });
        const layer = EmberObject.create({});

        this.testBed.args.onPlaceAdded(place, { target: layer });

        assert.strictEqual(layer.record_id, 'p_1', 'the layer is linked to the place');
        assert.deepEqual(this.tracked, [], 'a place does not move, so nothing tracks it');
    });

    test('clicking a driver, vehicle or place opens it in its own panel', async function (assert) {
        await this.renderMap();
        const driver = EmberObject.create({ id: 'd_1' });
        const vehicle = EmberObject.create({ id: 'v_1' });
        const place = EmberObject.create({ id: 'p_1' });

        this.testBed.args.onDriverClicked(driver);
        this.testBed.args.onVehicleClicked(vehicle);
        this.testBed.args.onPlaceClicked(place);

        assert.deepEqual(
            this.panelViews.map(([kind, record]) => [kind, record.id]),
            [
                ['driver', 'd_1'],
                ['vehicle', 'v_1'],
                ['place', 'p_1'],
            ],
            'each goes to the panel that knows how to show it'
        );
        assert.strictEqual(this.panelViews[0][2].size, 'xs', 'opened small, so the map is still visible beside it');
    });

    test('service areas and zones are added hidden', async function (assert) {
        await this.renderMap();
        const serviceArea = EmberObject.create({ id: 'sa_1' });
        const zone = EmberObject.create({ id: 'z_1' });
        const saLayer = EmberObject.create({});
        const zoneLayer = EmberObject.create({});

        this.testBed.args.onServiceAreaLayerAdded(serviceArea, { target: saLayer });
        this.testBed.args.onZoneLayerAdd(zone, { target: zoneLayer });

        assert.strictEqual(saLayer.record_id, 'sa_1', 'the boundary is linked to its record');
        assert.strictEqual(zoneLayer.record_id, 'z_1');
    });

    // -------------------------------------------------------------------------
    // Event plumbing
    // -------------------------------------------------------------------------

    test('an event fires the component method, the caller’s handler, and the universe', async function (assert) {
        this.handled = [];
        this.onDriverClicked = (...args) => this.handled.push(args);
        await render(hbs`<Map::LeafletLiveMap @onDriverClicked={{this.onDriverClicked}} />`);

        const driver = EmberObject.create({ id: 'd_1' });
        this.testBed.args.onDriverClicked(driver);

        assert.deepEqual(
            this.panelViews.map(([kind]) => kind),
            ['driver'],
            'the component does its own thing'
        );
        assert.deepEqual(this.handled, [[driver]], 'the caller is told too');
        assert.ok(
            this.universeEvents.some(([name, value]) => name === 'fleet-ops.live-map.on-driver-clicked' && value === driver),
            'and the event goes out on the universe, dasherized'
        );
    });

    test('an event with no matching method and no handler still reaches the universe', async function (assert) {
        await this.renderMap();

        // `onDriverAdded` has a component method; `onLoaded` has neither a method nor a handler
        // here, and is announced regardless so anything else listening can react.
        this.testBed.args.onDriverAdded(EmberObject.create({ id: 'd_1' }), { target: EmberObject.create({}) });

        assert.ok(
            this.universeEvents.some(([name]) => name === 'fleet-ops.live-map.on-driver-added'),
            'the event goes out whether or not anyone is listening'
        );
        assert.deepEqual(
            this.tracked.map((r) => r.id),
            ['d_1'],
            'and the component method still ran'
        );
    });

    // -------------------------------------------------------------------------
    // Viewport reload
    // -------------------------------------------------------------------------

    test('the viewport reload can be suspended and resumed by name', async function (assert) {
        await this.renderMap();
        await loadGoogleMap(this);
        const component = this.universe.get('component:fleet-ops:live-map');
        assert.ok(component, 'the component publishes itself once the map has loaded');

        assert.false(component.isViewportReloadSuspended, 'nothing is holding it to start with');

        component.suspendViewportReload('drawing');
        assert.true(component.isViewportReloadSuspended, 'a lock holds it');

        component.suspendViewportReload();
        component.resumeViewportReload('drawing');
        assert.true(component.isViewportReloadSuspended, 'and it stays held while another lock is out');

        component.resumeViewportReload();
        assert.false(component.isViewportReloadSuspended, 'only the last lock released lets it run again');
    });
});
