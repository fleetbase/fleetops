import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render, settled, clearRender } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';
import EmberObject from '@ember/object';
import telemetryTimestamp from '@fleetbase/fleetops-engine/utils/telemetry-timestamp';
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

module('Integration | telemetry | live device telemetry', function (hooks) {
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
            meta: { telemetry: { position_at: '2026-06-30T12:00:00Z', provider_at: '2026-06-30T12:00:10Z', ignition: true } },
            reload() {
                reloads++;
                this.set('meta', { telemetry: { position_at: new Date().toISOString(), provider_at: new Date().toISOString(), ignition: true } });
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

module('Unit | telemetry | map telemetry', function () {
    test('source times compare consistently across UTC and local offsets', function (assert) {
        assert.strictEqual(telemetryTimestamp('2026-09-15 12:00:00'), telemetryTimestamp('2026-09-15T15:00:00+03:00'));
    });
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
                additionalData: { position_at: time },
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

module('Integration | telemetry | capability-driven connection panel', function (hooks) {
    setupRenderingTest(hooks);

    test('unregistered capabilities do not render diagnostics or fetch anything', async function (assert) {
        let requests = 0;
        this.owner.register(
            'service:fetch',
            class extends Service {
                get() {
                    requests++;
                    return Promise.resolve({});
                }
            }
        );
        this.connection = EmberObject.create({ id: 'connection-1', public_id: 'telematic_1' });
        this.provider = { key: 'legacy', metadata: {} };
        await render(hbs`<Telematic::TelemetryStatus @resource={{this.connection}} @provider={{this.provider}} />`);
        assert.dom('[data-test-telemetry-status]').doesNotExist();
        assert.strictEqual(requests, 0);
        this.set('provider', { key: 'example', metadata: { telemetry: { durable_ingestion: true } } });
        await settled();
        assert.dom('[data-test-telemetry-status]').exists();
        assert.strictEqual(requests, 1, 'starts monitoring when capabilities become available');
        assert.dom('[data-test-telemetry-status] button').doesNotExist('poll-only adapters have no registration controls');
        this.set('provider', { key: 'legacy', metadata: {} });
        await settled();
        assert.dom('[data-test-telemetry-status]').doesNotExist();
    });

    test('any descriptor can opt into the same diagnostics and registration panel', async function (assert) {
        const requests = [];
        this.owner.register(
            'service:fetch',
            class extends Service {
                get(url) {
                    requests.push(url);
                    return Promise.resolve({ polling_enabled: true, last_poll: { status: 'completed' } });
                }
            }
        );
        this.connection = EmberObject.create({ id: 'connection-1', public_id: 'telematic_1' });
        this.provider = { key: 'example', metadata: { telemetry: { durable_ingestion: true, secure_webhooks: true, registration_instructions: 'Register at Example.' } } };
        await render(hbs`<Telematic::TelemetryStatus @resource={{this.connection}} @provider={{this.provider}} />`);
        assert.dom('[data-test-telemetry-status]').includesText('Live telemetry');
        assert.dom('[data-test-telemetry-status]').includesText('Register at Example.');
        assert.deepEqual(requests, ['telematics/telematic_1/telemetry-diagnostics']);
    });
});
