import serializeModel from '@fleetbase/ember-core/utils/serialize-model';
import serializeArray from '@fleetbase/ember-core/utils/serialize-model-array';

export default function serializePayload(payload) {
    const serialized = {
        pickup: serializeModel(payload.pickup),
        dropoff: serializeModel(payload.dropoff),
        entitities: serializeArray(payload.entities),
        waypoints: serializeArray(payload.waypoints),
    };

    return serialized;
}
