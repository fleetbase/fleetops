import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import Service from '@ember/service';

module('Unit | Service | geofence', function (hooks) {
    setupTest(hooks);

    hooks.beforeEach(function () {
        class MapManagerStubService extends Service {
            shownConfig = null;
            drawCreatedHandler = null;
            shownPolygons = [];
            overlays = new Map();
            removedLayers = [];
            shownLayers = [];
            registeredPolygons = [];

            showDrawControl(config = {}) {
                this.shownConfig = config;
            }

            on(event, handler) {
                if (event === 'draw:created') {
                    this.drawCreatedHandler = handler;
                }
            }

            off(event, handler) {
                if (event === 'draw:created' && this.drawCreatedHandler === handler) {
                    this.drawCreatedHandler = null;
                }
            }

            hideDrawControl() {}
            removeLayer(layer) {
                this.removedLayers.push(layer);
            }
            showPolygon(id) {
                this.shownPolygons.push(id);
            }
            showLayer(layer) {
                this.shownLayers.push(layer);
            }
            registerPolygon(id, layer, meta) {
                this.registeredPolygons.push({ id, layer, meta });
                this.overlays.set(id, layer);
                return layer;
            }
            hidePolygon() {}
            fitBounds() {}
            editPolygon() {
                return Promise.resolve({ type: 'unsupported' });
            }
            getOverlay(id) {
                return this.overlays.get(id) ?? null;
            }
        }

        class ServiceAreaActionsStubService extends Service {
            serviceAreas = [];
            loadAll = {
                perform: () => Promise.resolve(this.serviceAreas),
            };
            modal = {
                create: (attrs, options, saveOptions) => {
                    this.createdAttrs = attrs;
                    this.createOptions = options;
                    this.saveOptions = saveOptions;
                },
            };
        }

        class ZoneActionsStubService extends Service {
            modal = {
                create: (attrs, options, saveOptions) => {
                    this.createdAttrs = attrs;
                    this.createOptions = options;
                    this.saveOptions = saveOptions;
                },
            };
        }

        class NotificationsStubService extends Service {
            info() {}
            success() {}
        }

        class IntlStubService extends Service {
            t(key) {
                return key;
            }
        }

        this.owner.register('service:map-manager', MapManagerStubService);
        this.owner.register('service:service-area-actions', ServiceAreaActionsStubService);
        this.owner.register('service:zone-actions', ZoneActionsStubService);
        this.owner.register('service:notifications', NotificationsStubService);
        this.owner.register('service:intl', IntlStubService);
    });

    test('it exists', function (assert) {
        let service = this.owner.lookup('service:geofence');
        assert.ok(service);
    });

    test('createServiceArea uses the agnostic draw-control flow', function (assert) {
        const service = this.owner.lookup('service:geofence');
        const mapManager = this.owner.lookup('service:map-manager');
        const serviceAreaActions = this.owner.lookup('service:service-area-actions');

        service.createServiceArea();
        mapManager.drawCreatedHandler({
            geoJson: {
                type: 'Polygon',
                coordinates: [
                    [
                        [0, 0],
                        [1, 0],
                        [1, 1],
                        [0, 0],
                    ],
                ],
            },
        });

        assert.deepEqual(mapManager.shownConfig.tools, ['polygon', 'circle', 'rectangle']);
        assert.strictEqual(serviceAreaActions.createdAttrs.border.type, 'MultiPolygon');
    });

    test('created service area reloads canonical records before it is shown', async function (assert) {
        const service = this.owner.lookup('service:geofence');
        const mapManager = this.owner.lookup('service:map-manager');
        const serviceAreaActions = this.owner.lookup('service:service-area-actions');
        const savedServiceArea = { id: 'sa_1', zones: [] };
        const canonicalServiceArea = { id: 'sa_1', name: 'Test service area', zones: [] };
        const draftLayer = { id: 'draft-layer' };
        serviceAreaActions.serviceAreas = [canonicalServiceArea];

        service.createServiceArea();
        mapManager.drawCreatedHandler({
            layer: draftLayer,
            geoJson: {
                type: 'Polygon',
                coordinates: [
                    [
                        [0, 0],
                        [1, 0],
                        [1, 1],
                        [0, 0],
                    ],
                ],
            },
        });

        mapManager.overlays.set('sa_1', {});
        serviceAreaActions.saveOptions.callback(savedServiceArea);
        await Promise.resolve();

        assert.deepEqual(serviceAreaActions.serviceAreas, [canonicalServiceArea]);
        assert.deepEqual(mapManager.shownPolygons, ['sa_1']);
        assert.deepEqual(mapManager.registeredPolygons, []);
        assert.deepEqual(mapManager.shownLayers, []);
        assert.deepEqual(mapManager.removedLayers, [draftLayer]);
    });

    test('created zone uses the service_area relationship and reloads canonical records before it is shown', async function (assert) {
        const service = this.owner.lookup('service:geofence');
        const mapManager = this.owner.lookup('service:map-manager');
        const serviceAreaActions = this.owner.lookup('service:service-area-actions');
        const zoneActions = this.owner.lookup('service:zone-actions');
        const serviceArea = { id: 'sa_1', zones: [] };
        const zone = { id: 'zone_1', name: 'Saved zone' };
        const canonicalZone = { id: 'zone_1', name: 'Canonical zone' };
        const canonicalServiceArea = { id: 'sa_1', zones: [canonicalZone] };
        const draftLayer = { id: 'draft-layer' };
        serviceAreaActions.serviceAreas = [canonicalServiceArea];

        service.createZone(serviceArea);
        mapManager.drawCreatedHandler({
            layer: draftLayer,
            geoJson: {
                type: 'Polygon',
                coordinates: [
                    [
                        [0, 0],
                        [1, 0],
                        [1, 1],
                        [0, 0],
                    ],
                ],
            },
        });

        mapManager.overlays.set('zone_1', {});
        zoneActions.saveOptions.callback(zone);
        await Promise.resolve();

        assert.strictEqual(zoneActions.createdAttrs.service_area, serviceArea);
        assert.notOk(zoneActions.createdAttrs.serviceArea);
        assert.notOk(zoneActions.createdAttrs.service_area_uuid);
        assert.deepEqual(mapManager.shownPolygons, ['zone_1']);
        assert.deepEqual(mapManager.registeredPolygons, []);
        assert.deepEqual(mapManager.shownLayers, []);
        assert.deepEqual(mapManager.removedLayers, [draftLayer]);
    });

    test('saved draft layer is not removed before a persisted overlay is registered', async function (assert) {
        const service = this.owner.lookup('service:geofence');
        const mapManager = this.owner.lookup('service:map-manager');
        const serviceAreaActions = this.owner.lookup('service:service-area-actions');
        const serviceArea = { id: 'sa_1', zones: [] };
        const draftLayer = { id: 'draft-layer' };
        serviceAreaActions.serviceAreas = [serviceArea];

        service.createServiceArea();
        mapManager.drawCreatedHandler({
            layer: draftLayer,
            geoJson: {
                type: 'Polygon',
                coordinates: [
                    [
                        [0, 0],
                        [1, 0],
                        [1, 1],
                        [0, 0],
                    ],
                ],
            },
        });

        serviceAreaActions.saveOptions.callback(serviceArea);
        await Promise.resolve();

        assert.deepEqual(serviceAreaActions.serviceAreas, [serviceArea]);
        assert.deepEqual(mapManager.registeredPolygons, []);
        assert.deepEqual(mapManager.shownLayers, []);
        assert.deepEqual(mapManager.removedLayers, []);
    });
});
