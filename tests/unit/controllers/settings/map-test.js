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

        this.owner.register('service:fetch', FetchStubService);
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
                googleMapsMapType: 'satellite',
                showGoogleMapsTrafficLayer: true,
                showGoogleMapsTransitLayer: true,
            },
        });
        assert.deepEqual(mapSettings.appliedSettings, fetch.lastPost.payload.settings);
        assert.true(mapManager.appliedViewSettings, 'google map view settings are applied after save');
    });
});
