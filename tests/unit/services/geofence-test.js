import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import Service from '@ember/service';

module('Unit | Service | geofence', function (hooks) {
    setupTest(hooks);

    hooks.beforeEach(function () {
        class MapManagerStubService extends Service {
            shownConfig = null;
            drawCreatedHandler = null;

            showDrawControl(config = {}) {
                this.shownConfig = config;
            }

            once(event, handler) {
                if (event === 'draw:created') {
                    this.drawCreatedHandler = handler;
                }
            }

            hideDrawControl() {}
            showPolygon() {}
            hidePolygon() {}
            fitBounds() {}
            editPolygon() {
                return Promise.resolve({ type: 'unsupported' });
            }
            getOverlay() {
                return null;
            }
        }

        class ServiceAreaActionsStubService extends Service {
            modal = {
                create: (attrs) => {
                    this.createdAttrs = attrs;
                },
            };
        }

        class ZoneActionsStubService extends Service {
            modal = {
                create: (attrs) => {
                    this.createdAttrs = attrs;
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
});
