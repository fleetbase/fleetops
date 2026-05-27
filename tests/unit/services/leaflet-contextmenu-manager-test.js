import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import { A } from '@ember/array';

module('Unit | Service | leaflet-contextmenu-manager', function (hooks) {
    setupTest(hooks);

    test('it exists', function (assert) {
        let service = this.owner.lookup('service:leaflet-contextmenu-manager');
        assert.ok(service);
    });

    test('it removes registered context menus', function (assert) {
        let service = this.owner.lookup('service:leaflet-contextmenu-manager');
        let removedItemCount = 0;
        let unboundCount = 0;

        const layer = {
            contextmenu: {
                removeAllItems() {
                    removedItemCount++;
                },
                addItem() {},
                enable() {},
            },
            bindContextMenu() {
                return this;
            },
            unbindContextMenu() {
                unboundCount++;
                return this;
            },
        };

        service.createContextMenu('service-area:SA_1', layer, A([{ text: 'Delete Service Area: Central' }]));

        assert.ok(service.getRegistry('service-area:SA_1'), 'context menu is registered');

        const removedRegistry = service.removeContextMenu('service-area:SA_1');

        assert.strictEqual(removedRegistry.layer, layer, 'removed registry is returned');
        assert.strictEqual(removedItemCount, 1, 'native menu items are cleared');
        assert.strictEqual(unboundCount, 1, 'context menu is unbound from the layer');
        assert.notOk(service.getRegistry('service-area:SA_1'), 'context menu registry is removed');
    });
});
