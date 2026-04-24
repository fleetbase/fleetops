import serializePayload from 'dummy/utils/serialize-payload';
import { module, test } from 'qunit';

module('Unit | Utility | serialize-payload', function () {
    test('it serializes entities under the correct key', function (assert) {
        let result = serializePayload({
            pickup: { id: 'pickup-1' },
            dropoff: { id: 'dropoff-1' },
            entities: [{ id: 'entity-1' }],
            waypoints: [{ id: 'waypoint-1' }],
        });

        assert.true(Object.prototype.hasOwnProperty.call(result, 'entities'));
        assert.false(Object.prototype.hasOwnProperty.call(result, 'entitities'));
    });
});
