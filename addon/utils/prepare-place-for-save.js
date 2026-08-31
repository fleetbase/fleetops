export default function preparePlaceForSave(store, place) {
    if (!place || place.isNew || place.public_id) {
        return place;
    }

    const attributes = {};

    place.constructor.eachAttribute((attribute) => {
        attributes[attribute] = place[attribute];
    });

    return store.createRecord('place', attributes);
}
