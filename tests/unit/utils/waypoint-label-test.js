import { module, test } from 'qunit';
import waypointLabel from '@fleetbase/fleetops-engine/utils/waypoint-label';

module('Unit | Utility | waypoint-label', function () {
    test('it letters waypoints from A, accepting numeric strings', function (assert) {
        assert.strictEqual(waypointLabel(1), 'A');
        assert.strictEqual(waypointLabel('2'), 'B');
        assert.strictEqual(waypointLabel(26), 'Z');
        assert.strictEqual(waypointLabel(27), '10', 'past Z the base-36 digits roll over');
    });
});
