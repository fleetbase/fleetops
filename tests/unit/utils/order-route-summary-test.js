import { module, test } from 'qunit';
import { buildRouteTypeSummary } from '@fleetbase/fleetops-engine/utils/order-route-summary';

module('Unit | Utility | order-route-summary', function () {
    test('with no options it describes a plain pickup and dropoff route', function (assert) {
        const summary = buildRouteTypeSummary();

        assert.deepEqual(summary, {
            kind: 'pickup_dropoff',
            intermediateStopCount: 0,
            icon: 'exchange-alt',
            badgeClass: 'import-preview-badge import-preview-badge--gray',
            translationKey: 'orchestrator.col-preview-pickup-dropoff',
            translationOptions: {},
        });
        assert.deepEqual(buildRouteTypeSummary({}), summary, 'an empty options object takes every default');
    });

    test('intermediate stops without endpoints make a multi-stop route', function (assert) {
        const summary = buildRouteTypeSummary({ intermediateStopCount: '3' });

        assert.strictEqual(summary.kind, 'multi_stop');
        assert.strictEqual(summary.intermediateStopCount, 3, 'the count is coerced to a number');
        assert.strictEqual(summary.icon, 'route');
        assert.strictEqual(summary.badgeClass, 'import-preview-badge import-preview-badge--blue');
        assert.strictEqual(summary.translationKey, 'orchestrator.col-preview-multi-stop');
        assert.deepEqual(summary.translationOptions, { count: 3 });

        assert.strictEqual(buildRouteTypeSummary({ hasIntermediateWaypoints: true }).kind, 'multi_stop', 'waypoints alone count as intermediate stops');
        assert.strictEqual(buildRouteTypeSummary({ intermediateStopCount: -2 }).intermediateStopCount, 0, 'negative counts clamp to zero');
        assert.strictEqual(buildRouteTypeSummary({ intermediateStopCount: 'abc' }).kind, 'pickup_dropoff', 'a non-numeric count is no count');
    });

    test('intermediate stops with a pickup or dropoff make a pickup-dropoff-stops route', function (assert) {
        const withPickup = buildRouteTypeSummary({ intermediateStopCount: 2, hasPickup: true });
        assert.strictEqual(withPickup.kind, 'pickup_dropoff_stops');
        assert.strictEqual(withPickup.translationKey, 'orchestrator.col-preview-pickup-dropoff-stops');
        assert.deepEqual(withPickup.translationOptions, { count: 2 });

        assert.strictEqual(buildRouteTypeSummary({ hasIntermediateWaypoints: true, hasDropoff: true }).kind, 'pickup_dropoff_stops');
        assert.strictEqual(buildRouteTypeSummary({ hasPickup: true, hasDropoff: true }).kind, 'pickup_dropoff', 'endpoints without stops stay a plain route');
    });
});
