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
        this.owner.register('service:fetch', class extends Service {});
        const service = this.owner.lookup('service:map-settings');
        const settings = service.applySettings({ mapProvider: 'google' });

        assert.strictEqual(settings.googleMapsMapType, 'roadmap');
        assert.false(service.showGoogleMapsTrafficLayer);
        assert.false(service.showGoogleMapsTransitLayer);
    });

    test('it exposes no default map view until the company sets one', function (assert) {
        this.owner.register('service:fetch', class extends Service {});
        const service = this.owner.lookup('service:map-settings');
        service.applySettings({});

        assert.strictEqual(service.defaultMapCenter, null);
        assert.strictEqual(service.companyCountryCenter, null);
        assert.false(service.fitMapToVehicles);
    });

    test('it exposes the company default map view and country centre', function (assert) {
        this.owner.register('service:fetch', class extends Service {});
        const service = this.owner.lookup('service:map-settings');
        service.applySettings({
            defaultMapLatitude: -33.86,
            defaultMapLongitude: 151.2,
            defaultMapZoom: 10,
            fitMapToVehicles: true,
            companyMapCenter: { country: 'AU', latitude: -25.58, longitude: 134.5 },
        });

        assert.deepEqual(service.defaultMapCenter, { latitude: -33.86, longitude: 151.2, zoom: 10 });
        assert.deepEqual(service.companyCountryCenter, { latitude: -25.58, longitude: 134.5, zoom: 4 });
        assert.true(service.fitMapToVehicles);
    });
});
