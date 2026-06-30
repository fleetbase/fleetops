import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import Service from '@ember/service';

module('Unit | Service | map-manager', function (hooks) {
    setupTest(hooks);

    hooks.beforeEach(function () {
        class MapSettingsStubService extends Service {
            mapProvider = 'google';
            googleMapsApiKey = 'abc123';
            googleMapsMapId = 'map-123';
            googleMapsMapType = 'satellite';
            showGoogleMapsTrafficLayer = true;
            showGoogleMapsTransitLayer = true;
        }

        class LeafletContextmenuManagerStubService extends Service {
            getRegistry() {
                return null;
            }
        }

        class NotificationsStubService extends Service {
            info() {}
        }

        class RouteEngineStubService extends Service {
            getDisplayEngine() {
                return 'google';
            }

            async compute(name, waypoints) {
                return {
                    engine: name,
                    waypoints,
                    coordinates: waypoints,
                    bounds: [waypoints[0], waypoints[waypoints.length - 1]],
                    summary: {
                        totalDistance: 1000,
                        totalTime: 60,
                    },
                    raw: { ok: true },
                };
            }
        }

        class GoogleAdapterStubService extends Service {
            lastDrawControlConfig = null;
            lastRoute = null;
            lastViewSettings = null;

            initializeMap(_element, options = {}) {
                return { provider: 'google', options };
            }

            applyViewSettings(options = {}) {
                this.lastViewSettings = options;
                return options;
            }

            destroyMap() {}

            addRoutingControl(route, options = {}) {
                this.lastRoute = { route, options };
                return { id: 'route:1', route, tag: options.tag ?? null };
            }

            panTo() {
                return true;
            }

            showDrawControl(config = {}) {
                this.lastDrawControlConfig = config;
                return config;
            }
        }

        class LeafletAdapterStubService extends Service {
            initializeMap(_element, options = {}) {
                return { provider: 'leaflet', options };
            }

            destroyMap() {}
        }

        this.owner.register('service:map-settings', MapSettingsStubService);
        this.owner.register('service:leaflet-contextmenu-manager', LeafletContextmenuManagerStubService);
        this.owner.register('service:notifications', NotificationsStubService);
        this.owner.register('service:route-engine', RouteEngineStubService);
        this.owner.register('service:map-adapter/google', GoogleAdapterStubService);
        this.owner.register('service:map-adapter/leaflet', LeafletAdapterStubService);
    });

    test('it resolves provider from runtime settings', function (assert) {
        const service = this.owner.lookup('service:map-manager');
        service.setActiveProvider(service.getConfiguredProvider());

        assert.strictEqual(service.providerName, 'google');
        assert.true(service.isGoogleMaps);
    });

    test('it falls back to leaflet when provider is invalid', function (assert) {
        const mapSettings = this.owner.lookup('service:map-settings');
        mapSettings.mapProvider = 'missing';

        const service = this.owner.lookup('service:map-manager');
        service.setActiveProvider(service.getConfiguredProvider());

        assert.strictEqual(service.providerName, 'leaflet');
        assert.ok(service.adapter, 'adapter was resolved');
    });

    test('it delegates initialization with runtime api key', async function (assert) {
        const service = this.owner.lookup('service:map-manager');
        service.setActiveProvider('google');

        const map = await service.initializeMap(document.createElement('div'));

        assert.strictEqual(map.provider, 'google');
        assert.strictEqual(map.options.apiKey, 'abc123');
        assert.strictEqual(map.options.mapId, 'map-123');
        assert.strictEqual(map.options.mapTypeId, 'satellite');
        assert.true(map.options.showTrafficLayer);
        assert.true(map.options.showTransitLayer);
    });

    test('it applies google view settings to an active adapter', function (assert) {
        const service = this.owner.lookup('service:map-manager');
        service.setActiveProvider('google');

        const result = service.applyViewSettingsFromSettings();

        assert.deepEqual(result, {
            mapTypeId: 'satellite',
            showTrafficLayer: true,
            showTransitLayer: true,
        });
        assert.deepEqual(service.adapter.lastViewSettings, result);
    });

    test('it delegates draw control config to the active adapter', function (assert) {
        const service = this.owner.lookup('service:map-manager');
        service.setActiveProvider('google');

        const config = { tools: ['polygon', 'circle'], allowEdit: true, allowDelete: true };
        service.showDrawControl(config);

        assert.deepEqual(service.adapter.lastDrawControlConfig, config);
    });

    test('it computes and renders routing controls through the active adapter', async function (assert) {
        const service = this.owner.lookup('service:map-manager');
        service.setActiveProvider('google');
        service.setMapInstance({ id: 'map' });

        const handle = await service.addRoutingControl(
            [
                [1, 2],
                [3, 4],
            ],
            { tag: 'test-route', position: false }
        );

        assert.strictEqual(handle.id, 'route:1');
        assert.strictEqual(service.adapter.lastRoute.route.engine, 'google');
        assert.strictEqual(service.adapter.lastRoute.options.tag, 'test-route');
    });
});
