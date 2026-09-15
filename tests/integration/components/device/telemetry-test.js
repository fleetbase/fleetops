import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render, settled, clearRender } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';
import EmberObject from '@ember/object';
import { EventBuffer } from '@fleetbase/fleetops-engine/services/movement-tracker';

function stream() {
    let resolve;
    return {
        next() {
            return new Promise((done) => {
                resolve = done;
            });
        },
        send(value) {
            resolve?.({ value, done: false });
        },
        close() {
            resolve?.({ done: true });
        },
    };
}

module('Integration | AFAQY | live device telemetry', function (hooks) {
    setupRenderingTest(hooks);

    test('refreshes the open panel on signals and reconnect and exposes UTC timestamps', async function (assert) {
        const updates = stream();
        const reconnect = stream();
        let unsubscribed = false;
        this.owner.register(
            'service:socket',
            class extends Service {
                instance() {
                    return {
                        subscribe: () => ({
                            state: 'subscribed',
                            createConsumer: () => updates,
                            unsubscribe: async () => {
                                unsubscribed = true;
                            },
                        }),
                        listener: () => ({ createConsumer: () => reconnect }),
                    };
                }
            }
        );
        let reloads = 0;
        this.device = EmberObject.create({
            id: 'device-1',
            meta: { afaqy: { position_at: '2026-06-30T12:00:00Z', provider_at: '2026-06-30T12:00:10Z', ignition: true } },
            reload() {
                reloads++;
                this.set('meta', { afaqy: { position_at: new Date().toISOString(), provider_at: new Date().toISOString(), ignition: true } });
                return Promise.resolve(this);
            },
        });
        await render(hbs`<Device::Telemetry @resource={{this.device}} />`);
        assert.dom('[data-test-device-telemetry]').includesText('Position stale');
        assert.dom('[data-test-device-telemetry]').includesText('Telemetry timing (UTC)');
        updates.send({ event: 'device.telemetry_updated' });
        await settled();
        assert.strictEqual(reloads, 1);
        assert.dom('[data-test-device-telemetry]').includesText('Position current');
        reconnect.send({});
        await settled();
        assert.strictEqual(reloads, 2);
        await clearRender();
        assert.true(unsubscribed, 'releases socket subscription when panel closes');
    });
});

module('Unit | AFAQY | map telemetry', function () {
    test('updates the map and record without reload and ignores reversed location broadcasts', async function (assert) {
        const model = EmberObject.create({ id: 'vehicle-1' });
        const positions = [];
        const buffer = new EventBuffer(model, {
            mapManager: {
                hasMarker: () => true,
                setMarkerRotation: () => {},
                updateMarkerPosition: (...args) => positions.push(args),
            },
        });
        const event = (time, latitude) => ({
            event: 'vehicle.location_changed',
            created_at: time,
            data: {
                id: 'vehicle-1',
                location: { type: 'Point', coordinates: [46.7, latitude] },
                speed: 20,
                heading: 90,
                additionalData: { provider: 'afaqy', position_at: time },
            },
        });
        buffer.add(event('2026-09-15T12:00:00Z', 24));
        await buffer.process.perform();
        buffer.add(event('2026-09-15T11:59:00Z', 23));
        await buffer.process.perform();
        assert.strictEqual(positions.length, 1);
        assert.deepEqual(positions[0].slice(0, 3), ['vehicle-1', 24, 46.7]);
        assert.deepEqual(model.location.coordinates, [46.7, 24]);
        buffer.stop();
    });
});
