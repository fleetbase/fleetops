import { module, test } from 'qunit';
import OperationsOrdersIndexRoute from '@fleetbase/fleetops-engine/routes/operations/orders/index';

module('Unit | Route | operations/orders/index', function () {
    test('closes open resource context panels when leaving the dashboard route', function (assert) {
        let closeAllCalled = false;
        const route = {
            resourceContextPanel: {
                closeAll() {
                    closeAllCalled = true;
                },
            },
        };

        OperationsOrdersIndexRoute.prototype.deactivate.call(route);

        assert.true(closeAllCalled, 'open context panels are closed when the dashboard route is exited');
    });
});
