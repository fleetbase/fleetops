import { module, test } from 'qunit';
import preparePlaceForSave from 'dummy/utils/prepare-place-for-save';

class PlaceRecordStub {
    static eachAttribute(callback) {
        ['public_id', 'name', 'street1', 'street2', 'location', 'eta'].forEach(callback);
    }

    constructor(attributes) {
        Object.assign(this, attributes);
    }
}

module('Unit | Utility | prepare-place-for-save', function () {
    test('it converts a geocoder-only record into a locally createable place', function (assert) {
        const geocodedPlace = new PlaceRecordStub({
            isNew: false,
            public_id: null,
            name: 'Delivery entrance',
            street1: '205 Dostyk Avenue',
            street2: 'Entrance 2',
            location: { type: 'Point', coordinates: [76.9585057, 43.2343247] },
            eta: null,
        });
        const store = {
            createRecord(modelName, attributes) {
                return { modelName, isNew: true, ...attributes };
            },
        };

        const preparedPlace = preparePlaceForSave(store, geocodedPlace);

        assert.notStrictEqual(preparedPlace, geocodedPlace);
        assert.true(preparedPlace.isNew, 'the edit modal will save the place with POST');
        assert.strictEqual(preparedPlace.modelName, 'place');
        assert.strictEqual(preparedPlace.street2, 'Entrance 2');
        assert.deepEqual(preparedPlace.location, geocodedPlace.location);
    });

    test('it preserves persisted, new, and empty place selections', function (assert) {
        const store = {
            createRecord() {
                assert.step('createRecord');
            },
        };
        const persistedPlace = new PlaceRecordStub({ isNew: false, public_id: 'place_existing' });
        const newPlace = new PlaceRecordStub({ isNew: true, public_id: null });

        assert.strictEqual(preparePlaceForSave(store, persistedPlace), persistedPlace);
        assert.strictEqual(preparePlaceForSave(store, newPlace), newPlace);
        assert.strictEqual(preparePlaceForSave(store, null), null);
        assert.verifySteps([]);
    });
});
