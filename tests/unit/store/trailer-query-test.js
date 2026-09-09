import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import JSONAPIAdapter from '@ember-data/adapter/json-api';

/**
 * Regression coverage for the original "The response to store.query is expected to
 * be an array but it was a single record" failure: the internal API answers with a
 * `{ trailers: [...] }` envelope even though Trailer rows live in the shared assets
 * table, and Ember Data must normalize that envelope into a collection.
 */
module('Unit | Store | trailer query normalization', function (hooks) {
    setupTest(hooks);

    function registerAdapter(owner, payload) {
        owner.register(
            'adapter:trailer',
            class TrailerAdapterStub extends JSONAPIAdapter {
                query() {
                    return Promise.resolve(payload);
                }
                queryRecord() {
                    return Promise.resolve(payload);
                }
            }
        );
    }

    test('a populated `trailers` collection envelope becomes an array of Trailer records', async function (assert) {
        registerAdapter(this.owner, {
            trailers: [
                {
                    id: 'trailer-1',
                    uuid: 'trailer-1',
                    public_id: 'trailer_one',
                    name: 'Reefer 12',
                    type: 'reefer',
                    status: 'available',
                    attachment_state: 'detached',
                    connectivity_status: 'never_connected',
                },
                {
                    id: 'trailer-2',
                    uuid: 'trailer-2',
                    public_id: 'trailer_two',
                    name: 'Flatbed 3',
                    type: 'flatbed',
                    status: 'in_use',
                    attachment_state: 'attached',
                    current_vehicle_name: 'Truck 1',
                },
            ],
            meta: { total: 2, current_page: 1, last_page: 1 },
        });
        const store = this.owner.lookup('service:store');

        const trailers = await store.query('trailer', { page: 1 });

        assert.strictEqual(trailers.length, 2);
        assert.strictEqual(trailers.meta.total, 2);
        assert.deepEqual(
            trailers.map((trailer) => trailer.constructor.modelName),
            ['trailer', 'trailer']
        );
        assert.strictEqual(trailers.firstObject.displayName, 'Reefer 12');
        assert.true(trailers.lastObject.isAttached);
    });

    test('an empty `trailers` collection envelope stays an empty array', async function (assert) {
        registerAdapter(this.owner, { trailers: [], meta: { total: 0 } });
        const store = this.owner.lookup('service:store');

        const trailers = await store.query('trailer', { page: 1 });

        assert.strictEqual(trailers.length, 0);
        assert.strictEqual(trailers.meta.total, 0);
    });

    test('a single `trailer` envelope with embedded towing state hydrates the related vehicle', async function (assert) {
        registerAdapter(this.owner, {
            trailer: {
                id: 'trailer-1',
                uuid: 'trailer-1',
                public_id: 'trailer_one',
                name: 'Reefer 12',
                attachment_state: 'attached',
                current_vehicle_name: 'Truck 1',
                current_vehicle: { id: 'vehicle-1', uuid: 'vehicle-1', public_id: 'vehicle_one', name: 'Truck 1', plate_number: 'TRK-1' },
                current_connection: { id: 'connection-1', uuid: 'connection-1', public_id: 'connection_one', relationship_type: 'towing', position: 1, active: true },
            },
        });
        const store = this.owner.lookup('service:store');

        const trailer = await store.queryRecord('trailer', { public_id: 'trailer_one', single: true });

        assert.strictEqual(trailer.public_id, 'trailer_one');
        assert.strictEqual(trailer.current_vehicle.plate_number, 'TRK-1');
        assert.strictEqual(trailer.current_connection.position, 1);
        assert.true(trailer.isAttached);
    });
});
