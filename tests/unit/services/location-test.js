import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import Service from '@ember/service';
import LocationService from '@fleetbase/fleetops-engine/services/location';

module('Unit | Service | location', function (hooks) {
    setupTest(hooks);

    hooks.beforeEach(function () {
        class FetchStubService extends Service {
            coordinates = [];
            requests = 0;

            cachedGet() {
                this.requests++;
                return Promise.resolve(this.coordinates);
            }
        }

        class CurrentUserStubService extends Service {
            whois = {};

            getOption(key, defaultValue) {
                return key === 'whois' ? this.whois : defaultValue;
            }
        }

        class UniverseStubService extends Service {
            events = [];

            trigger(name, payload) {
                this.events.push({ name, payload });
            }
        }

        class MapSettingsStubService extends Service {
            defaultMapCenter = null;
            companyCountryCenter = null;
            loads = 0;

            load() {
                this.loads++;
                return Promise.resolve({});
            }
        }

        this.owner.register('service:fetch', FetchStubService);
        this.owner.register('service:current-user', CurrentUserStubService);
        this.owner.register('service:universe', UniverseStubService);
        this.owner.register('service:map-settings', MapSettingsStubService);

        this.fetch = this.owner.lookup('service:fetch');
        this.currentUser = this.owner.lookup('service:current-user');
        this.universe = this.owner.lookup('service:universe');
        this.mapSettings = this.owner.lookup('service:map-settings');
    });

    test('it starts at the hardcoded default with no suggested zoom', function (assert) {
        const service = this.owner.lookup('service:location');

        assert.strictEqual(service.getLatitude(), LocationService.DEFAULT_LATITUDE);
        assert.strictEqual(service.getLongitude(), LocationService.DEFAULT_LONGITUDE);
        assert.strictEqual(service.getZoom(), null);
    });

    test('a company default centre wins over order destinations and the browser', async function (assert) {
        this.mapSettings.defaultMapCenter = { latitude: -33.86, longitude: 151.2, zoom: 9 };
        this.fetch.coordinates = [{ coordinates: [103.8, 1.3] }];
        const service = this.owner.lookup('service:location');

        const location = await service.getUserLocation();

        assert.deepEqual(location, { latitude: -33.86, longitude: 151.2 });
        assert.strictEqual(service.getZoom(), 9);
        assert.strictEqual(this.fetch.requests, 0, 'no guessing when the company chose a view');
        assert.deepEqual(this.universe.events, [{ name: 'user.located', payload: { latitude: -33.86, longitude: 151.2, zoom: 9 } }]);
    });

    test('without a default centre it opens on active order destinations', async function (assert) {
        this.fetch.coordinates = [{ coordinates: [0, 0] }, { coordinates: [151.2, -33.86] }];
        const service = this.owner.lookup('service:location');

        assert.deepEqual(await service.getUserLocation(), { latitude: -33.86, longitude: 151.2 });
        assert.strictEqual(service.getZoom(), null);
        assert.strictEqual(this.mapSettings.loads, 1);
    });

    test('with no active orders it asks the browser', async function (assert) {
        const service = this.owner.lookup('service:location');
        service.getUserLocationFromNavigator = async () => ({ latitude: -37.81, longitude: 144.96 });

        assert.deepEqual(await service.getUserLocation(), { latitude: -37.81, longitude: 144.96 });
    });

    test('the IP lookup is used before the country centre', async function (assert) {
        this.fetch.coordinates = [{ coordinates: [0, 0] }];
        this.currentUser.whois = { latitude: '-31.95', longitude: '115.86' };
        this.mapSettings.companyCountryCenter = { latitude: -25.58, longitude: 134.5, zoom: 4 };
        const service = this.owner.lookup('service:location');

        assert.deepEqual(await service.getUserLocation(), { latitude: -31.95, longitude: 115.86 });
        assert.strictEqual(service.getZoom(), null);
    });

    test('a failed IP lookup falls back to the company country, not Singapore', async function (assert) {
        this.fetch.cachedGet = () => Promise.reject(new Error('offline'));
        this.currentUser.whois = { latitude: null, longitude: null, _source: 'fallback' };
        this.mapSettings.companyCountryCenter = { latitude: -25.58, longitude: 134.5, zoom: 4 };
        const service = this.owner.lookup('service:location');

        assert.deepEqual(await service.getUserLocation(), { latitude: -25.58, longitude: 134.5 });
        assert.strictEqual(service.getZoom(), 4);
    });

    test('with nothing known it uses the hardcoded default', function (assert) {
        this.currentUser.whois = null;
        const service = this.owner.lookup('service:location');

        assert.deepEqual(service.getUserLocationFromWhois(), { latitude: LocationService.DEFAULT_LATITUDE, longitude: LocationService.DEFAULT_LONGITUDE });
        assert.true(service.located);
    });

    test('relocate re-resolves after the company default changes', async function (assert) {
        this.fetch.coordinates = [{ coordinates: [151.2, -33.86] }];
        const service = this.owner.lookup('service:location');
        await service.getUserLocation();

        this.mapSettings.defaultMapCenter = { latitude: -27.47, longitude: 153.02, zoom: 11 };
        assert.deepEqual(await service.getUserLocation(), { latitude: -33.86, longitude: 151.2 }, 'resolved once per session');
        assert.deepEqual(await service.relocate(), { latitude: -27.47, longitude: 153.02 });
        assert.strictEqual(service.getZoom(), 11);
    });
});
