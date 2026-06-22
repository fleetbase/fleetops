import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import Service from '@ember/service';

module('Unit | Service | map-settings', function (hooks) {
    setupTest(hooks);

    test('it loads server-backed settings', async function (assert) {
        class FetchStubService extends Service {
            get() {
                return Promise.resolve({
                    mapProvider: 'google',
                    googleMapsApiKey: 'abc123',
                    googleMapsMapType: 'satellite',
                    showGoogleMapsTrafficLayer: true,
                    showGoogleMapsTransitLayer: true,
                });
            }
        }

        this.owner.register('service:fetch', FetchStubService);

        const service = this.owner.lookup('service:map-settings');
        const settings = await service.load();

        assert.strictEqual(settings.mapProvider, 'google');
        assert.strictEqual(service.googleMapsApiKey, 'abc123');
        assert.strictEqual(service.googleMapsMapType, 'satellite');
        assert.true(service.showGoogleMapsTrafficLayer);
        assert.true(service.showGoogleMapsTransitLayer);
        assert.true(service.isGoogleMaps);
    });

    test('it falls back safely when loading fails', async function (assert) {
        class FetchStubService extends Service {
            get() {
                return Promise.reject(new Error('failed'));
            }
        }

        this.owner.register('service:fetch', FetchStubService);

        const service = this.owner.lookup('service:map-settings');
        const settings = await service.load();

        assert.strictEqual(settings.mapProvider, 'leaflet');
        assert.strictEqual(service.googleMapsApiKey, '');
        assert.strictEqual(service.googleMapsMapType, 'roadmap');
        assert.false(service.showGoogleMapsTrafficLayer);
        assert.false(service.showGoogleMapsTransitLayer);
        assert.false(service.isGoogleMaps);
    });

    test('it applies google view layer defaults when values are omitted', function (assert) {
        const service = this.owner.lookup('service:map-settings');
        const settings = service.applySettings({ mapProvider: 'google' });

        assert.strictEqual(settings.googleMapsMapType, 'roadmap');
        assert.false(service.showGoogleMapsTrafficLayer);
        assert.false(service.showGoogleMapsTransitLayer);
    });
});
