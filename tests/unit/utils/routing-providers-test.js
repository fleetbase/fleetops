import isRoutingProviderAllowed, { GOVERNED_ROUTING_PROVIDERS } from 'dummy/utils/routing-providers';
import { module, test } from 'qunit';

module('Unit | Utility | routing-providers', function () {
    test('built-in providers follow the allowed list', function (assert) {
        assert.deepEqual(GOVERNED_ROUTING_PROVIDERS, ['google', 'valhalla', 'osrm', 'vroom']);
        assert.true(isRoutingProviderAllowed('osrm', ['osrm', 'valhalla']));
        assert.false(isRoutingProviderAllowed('google', ['osrm', 'valhalla']));
        assert.false(isRoutingProviderAllowed('Valhalla', ['osrm']));
    });

    test('extension engines and a missing allowed list are never filtered', function (assert) {
        assert.true(isRoutingProviderAllowed('mapbox', ['osrm']));
        assert.true(isRoutingProviderAllowed('google', undefined));
        assert.true(isRoutingProviderAllowed(null, ['osrm']));
    });
});
