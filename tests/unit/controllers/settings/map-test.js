import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import Service from '@ember/service';

module('Unit | Controller | settings/map', function (hooks) {
    setupTest(hooks);

    hooks.beforeEach(function () {
        class FetchStubService extends Service {
            lastPost = null;

            post(url, payload) {
                this.lastPost = { url, payload };
                return Promise.resolve(payload.settings);
            }
        }

        class NotificationsStubService extends Service {
            success() {}
            serverError() {}
        }

        class IntlStubService extends Service {
            t(key) {
                return key;
            }
        }

        class MapManagerStubService extends Service {
            appliedViewSettings = false;

            setActiveProvider() {}

            applyViewSettingsFromSettings() {
                this.appliedViewSettings = true;
            }
        }

        class MapSettingsStubService extends Service {
            appliedSettings = null;

            load() {
                return Promise.resolve({
                    mapProvider: 'google',
                    googleMapsMapType: 'hybrid',
                    showGoogleMapsTrafficLayer: true,
                    showGoogleMapsTransitLayer: false,
                });
            }

            applySettings(settings) {
                this.appliedSettings = settings;
                return settings;
            }
        }

        class LocationStubService extends Service {
            relocations = 0;

            relocate() {
                this.relocations++;
                return Promise.resolve({});
            }
        }

        this.owner.register('service:fetch', FetchStubService);
        this.owner.register('service:location', LocationStubService);
        this.owner.register('service:notifications', NotificationsStubService);
        this.owner.register('service:intl', IntlStubService);
        this.owner.register('service:map-manager', MapManagerStubService);
        this.owner.register('service:map-settings', MapSettingsStubService);
    });

    test('it saves google display preferences', async function (assert) {
        const controller = this.owner.lookup('controller:settings/map');
        controller.mapProvider = 'google';
        controller.googleMapsMapType = 'satellite';
        controller.showGoogleMapsTrafficLayer = true;
        controller.showGoogleMapsTransitLayer = true;

        await controller.saveSettings.perform();

        const fetch = this.owner.lookup('service:fetch');
        const mapSettings = this.owner.lookup('service:map-settings');
        const mapManager = this.owner.lookup('service:map-manager');

        assert.strictEqual(fetch.lastPost.url, 'fleet-ops/settings/map');
        assert.deepEqual(fetch.lastPost.payload, {
            settings: {
                mapProvider: 'google',
                leafletTileUrl: '',
                leafletDarkTileUrl: '',
                googleMapsMapType: 'satellite',
                showGoogleMapsTrafficLayer: true,
                showGoogleMapsTransitLayer: true,
                defaultMapLatitude: null,
                defaultMapLongitude: null,
                defaultMapZoom: null,
                fitMapToVehicles: false,
            },
        });
        assert.deepEqual(mapSettings.appliedSettings, fetch.lastPost.payload.settings);
        assert.true(mapManager.appliedViewSettings, 'google map view settings are applied after save');
    });

    test('it loads the default map view', async function (assert) {
        class MapSettingsWithViewStubService extends Service {
            load() {
                return Promise.resolve({ mapProvider: 'leaflet', defaultMapLatitude: -33.86, defaultMapLongitude: 151.2, defaultMapZoom: 9, fitMapToVehicles: true });
            }
        }
        this.owner.unregister('service:map-settings');
        this.owner.register('service:map-settings', MapSettingsWithViewStubService);

        const controller = this.owner.lookup('controller:settings/map');
        await controller.getSettings.last;

        assert.strictEqual(controller.defaultMapLatitude, -33.86);
        assert.strictEqual(controller.defaultMapLongitude, 151.2);
        assert.strictEqual(controller.defaultMapZoom, 9);
        assert.true(controller.fitMapToVehicles);
        assert.true(controller.hasDefaultMapCenter);
        assert.deepEqual(controller.defaultMapCenterPoint, { type: 'Point', coordinates: [151.2, -33.86] });
    });

    test('it saves the default map view and re-resolves where maps open', async function (assert) {
        const controller = this.owner.lookup('controller:settings/map');
        await controller.getSettings.last;

        assert.strictEqual(controller.defaultMapCenterPoint, null, 'no centre loaded');

        controller.setDefaultMapCenter({ latitude: '-27.47', longitude: '153.02' });
        controller.defaultMapZoom = '11';
        controller.fitMapToVehicles = true;
        await controller.saveSettings.perform();

        const fetch = this.owner.lookup('service:fetch');
        const location = this.owner.lookup('service:location');
        assert.deepEqual(
            {
                defaultMapLatitude: fetch.lastPost.payload.settings.defaultMapLatitude,
                defaultMapLongitude: fetch.lastPost.payload.settings.defaultMapLongitude,
                defaultMapZoom: fetch.lastPost.payload.settings.defaultMapZoom,
                fitMapToVehicles: fetch.lastPost.payload.settings.fitMapToVehicles,
            },
            { defaultMapLatitude: -27.47, defaultMapLongitude: 153.02, defaultMapZoom: 11, fitMapToVehicles: true }
        );
        assert.strictEqual(location.relocations, 1);
    });

    test('it never saves half a centre or an invalid zoom, and clearing resets the input', async function (assert) {
        const controller = this.owner.lookup('controller:settings/map');
        await controller.getSettings.last;

        controller.setDefaultMapCenter({ latitude: '-27.47', longitude: '' });
        controller.defaultMapZoom = '4.5';
        await controller.saveSettings.perform();

        const { settings } = this.owner.lookup('service:fetch').lastPost.payload;
        assert.strictEqual(settings.defaultMapLatitude, null);
        assert.strictEqual(settings.defaultMapLongitude, null);
        assert.strictEqual(settings.defaultMapZoom, null);

        controller.setDefaultMapCenter({ latitude: 1, longitude: 2 });
        const inputKey = controller.defaultCenterInputKey;
        controller.clearDefaultMapCenter();
        assert.false(controller.hasDefaultMapCenter);
        assert.strictEqual(controller.defaultCenterInputKey, inputKey + 1);
        controller.setDefaultMapCenter();
        assert.strictEqual(controller.defaultMapLatitude, null);
    });
});
