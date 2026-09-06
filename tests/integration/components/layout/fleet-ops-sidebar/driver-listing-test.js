import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, findAll, render, settled } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';
import StubEventedService from 'dummy/utils/stub-evented-service';

const ORDERS_ROUTE = 'console.fleet-ops.operations.orders.index';
const FLEETS_ROUTE = 'console.fleet-ops.management.fleets.index';

class StoreStub extends Service {
    queries = [];
    result = [];
    error = null;

    query(modelName, params) {
        this.queries.push({ modelName, params });

        if (this.error) {
            return Promise.reject(this.error);
        }

        return Promise.resolve(this.result);
    }
}

class DriverActionsStub extends Service {
    calls = [];

    panel = {
        create: (...args) => this.calls.push({ method: 'panel.create', args }),
        view: (...args) => this.calls.push({ method: 'panel.view', args }),
        edit: (...args) => this.calls.push({ method: 'panel.edit', args }),
    };

    assignOrder(...args) {
        this.calls.push({ method: 'assignOrder', args });
    }

    assignVehicle(...args) {
        this.calls.push({ method: 'assignVehicle', args });
    }

    locate(...args) {
        this.calls.push({ method: 'locate', args });
    }

    delete(...args) {
        this.calls.push({ method: 'delete', args });
    }
}

class MapManagerStub extends Service {
    ready = false;
    waits = [];
    focusCalls = [];

    livemap = {
        isReady: () => this.ready,
    };

    waitForMap(options) {
        this.waits.push(options);
        return Promise.resolve();
    }

    focusResource(resource, zoom, options) {
        this.focusCalls.push({ resource, zoom, options });
    }
}

class NotificationsStub extends Service {
    errors = [];

    serverError(error) {
        this.errors.push(error);
    }
}

class AbilitiesStub extends Service {
    denied = new Set();

    can(permission) {
        return !this.denied.has(permission);
    }

    cannot(permission) {
        return !this.can(permission);
    }
}

class UniverseStub extends StubEventedService {}

const ANN = { id: 'driver_1', name: 'Ann Driver', online: true };
const BOB = { id: 'driver_2', name: 'Bob Driver', online: false };

// The panel's own dropdown renders out of place, which drops its wrapper class; its trigger is the
// only basic-dropdown trigger in the panel that is not a driver row's.
const PANEL_DROPDOWN_TRIGGER = '.next-driver-listing .ember-basic-dropdown-trigger:not(.next-nav-item-dropdown-button)';

function menuLabels() {
    return findAll('.next-dd-item').map((element) => element.textContent.replace(/\s+/g, ' ').trim());
}

async function clickMenuItem(label) {
    const item = findAll('.next-dd-item').find((element) => element.textContent.replace(/\s+/g, ' ').trim() === label);

    if (!item) {
        throw new Error(`no dropdown item labelled "${label}" (have: ${menuLabels().join(', ')})`);
    }

    await click(item);
}

module('Integration | Component | layout/fleet-ops-sidebar/driver-listing', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:store', StoreStub);
        this.owner.register('service:driver-actions', DriverActionsStub);
        this.owner.register('service:map-manager', MapManagerStub);
        this.owner.register('service:notifications', NotificationsStub);
        this.owner.register('service:abilities', AbilitiesStub);
        this.owner.register('service:universe', UniverseStub);

        this.store = this.owner.lookup('service:store');
        this.driverActions = this.owner.lookup('service:driver-actions');
        this.mapManager = this.owner.lookup('service:map-manager');
        this.notifications = this.owner.lookup('service:notifications');
        this.abilities = this.owner.lookup('service:abilities');
        this.universe = this.owner.lookup('service:universe');
        this.hostRouter = this.owner.lookup('service:host-router');

        this.store.result = [ANN, BOB];
    });

    test('it lists the first twenty drivers with their online state', async function (assert) {
        await render(hbs`<Layout::FleetOpsSidebar::DriverListing @title="Drivers" @icon="id-card" />`);

        assert.deepEqual(this.store.queries, [{ modelName: 'driver', params: { limit: 20, without: ['vendor'] } }], 'queries drivers without their vendor');
        assert.dom('.next-driver-listing').includesText('Drivers');
        assert.dom('.driver-nav-item').exists({ count: 2 });
        assert.dom(findAll('.driver-nav-item')[0]).includesText('Ann Driver');
        assert.dom(findAll('.driver-nav-item')[1]).includesText('Bob Driver');
        assert.dom('.driver-nav-item svg.text-green-500').exists({ count: 1 }, 'online drivers get a green dot');
        assert.dom('.driver-nav-item svg.text-yellow-200').exists({ count: 1 }, 'offline drivers get a yellow dot');
    });

    test('it reports a failed driver query and renders nothing', async function (assert) {
        this.store.error = new Error('boom');

        await render(hbs`<Layout::FleetOpsSidebar::DriverListing @title="Drivers" />`);

        assert.strictEqual(this.notifications.errors.length, 1, 'the server error is surfaced');
        assert.strictEqual(this.notifications.errors[0].message, 'boom');
        assert.dom('.driver-nav-item').doesNotExist();
    });

    test('it refetches when a driver is saved anywhere in the console', async function (assert) {
        await render(hbs`<Layout::FleetOpsSidebar::DriverListing @title="Drivers" />`);

        assert.strictEqual(this.store.queries.length, 1);

        this.store.result = [ANN];
        this.universe.trigger('fleet-ops.driver.saved');
        await settled();

        assert.strictEqual(this.store.queries.length, 2, 'the listing reloads');
        assert.dom('.driver-nav-item').exists({ count: 1 });
    });

    test('clicking a driver jumps to the live map and focuses the driver once the map is ready', async function (assert) {
        this.mapManager.ready = true;
        this.set('focused', []);
        this.set('onFocusDriver', (driver) => this.focused.push(driver));

        await render(hbs`<Layout::FleetOpsSidebar::DriverListing @title="Drivers" @onFocusDriver={{this.onFocusDriver}} />`);
        await click(findAll('.driver-nav-item')[0]);

        assert.deepEqual(this.hostRouter.calls, [{ method: 'transitionTo', args: [ORDERS_ROUTE, { queryParams: { layout: 'map' } }] }], 'transitions to the map layout');
        assert.deepEqual(this.mapManager.waits, [{ timeoutMs: 8000 }], 'waits for the map before focusing');
        assert.strictEqual(this.mapManager.focusCalls.length, 1);

        const [{ resource, zoom, options }] = this.mapManager.focusCalls;
        assert.strictEqual(resource, ANN);
        assert.strictEqual(zoom, 16);
        assert.deepEqual(options.paddingBottomRight, [300, 200]);
        assert.deepEqual(this.focused, [ANN], 'the optional @onFocusDriver callback receives the driver');

        options.moveend();
        assert.deepEqual(this.driverActions.calls, [{ method: 'panel.view', args: [ANN, { closeOnTransition: true }] }], 'the driver panel opens once the map settles');
    });

    test('clicking a driver defers focusing until the live map loads, even when the transition fails', async function (assert) {
        this.hostRouter.transitionTo = () => Promise.reject(new Error('blocked'));

        await render(hbs`<Layout::FleetOpsSidebar::DriverListing @title="Drivers" />`);
        await click(findAll('.driver-nav-item')[1]);

        assert.strictEqual(this.mapManager.focusCalls.length, 0, 'nothing to focus on yet');

        this.mapManager.ready = true;
        this.universe.trigger('fleet-ops.live-map.loaded');
        await settled();

        assert.strictEqual(this.mapManager.focusCalls.length, 1, 'focuses once the map announces itself');
        assert.strictEqual(this.mapManager.focusCalls[0].resource, BOB);

        this.universe.trigger('fleet-ops.live-map.loaded');
        await settled();
        assert.strictEqual(this.mapManager.focusCalls.length, 1, 'the one-shot listener does not fire twice');
    });

    test('clicking the panel title transitions to @route, collapsing the panel only on the fleets index', async function (assert) {
        await render(hbs`<Layout::FleetOpsSidebar::DriverListing @title="Drivers" @route="console.fleet-ops.management.drivers.index" />`);

        assert.dom('.next-driver-listing').hasClass('is-open');

        await click('.next-driver-listing .next-content-panel-title-container');

        assert.deepEqual(this.hostRouter.calls, [{ method: 'transitionTo', args: ['console.fleet-ops.management.drivers.index'] }]);
        assert.dom('.next-driver-listing').hasClass('is-open', 'the panel stays open away from the fleets index');

        this.hostRouter.currentRouteName = FLEETS_ROUTE;
        await click('.next-driver-listing .next-content-panel-title-container');

        assert.strictEqual(this.hostRouter.calls.length, 2);
        assert.dom('.next-driver-listing').hasClass('is-closed', 'on the fleets index the title click also collapses the panel');
    });

    test('clicking the panel title without a @route does nothing', async function (assert) {
        await render(hbs`<Layout::FleetOpsSidebar::DriverListing @title="Drivers" />`);
        await click('.next-driver-listing .next-content-panel-title-container');

        assert.deepEqual(this.hostRouter.calls, []);
    });

    test('the panel dropdown offers creating a driver when permitted', async function (assert) {
        await render(hbs`<Layout::FleetOpsSidebar::DriverListing @title="Drivers" />`);

        await click(PANEL_DROPDOWN_TRIGGER);
        assert.deepEqual(menuLabels(), ['Create a new Driver']);

        await clickMenuItem('Create a new Driver');
        assert.deepEqual(this.driverActions.calls, [{ method: 'panel.create', args: [] }]);
    });

    test('the panel dropdown is hidden without the create permission', async function (assert) {
        this.abilities.denied.add('fleet-ops create driver');

        await render(hbs`<Layout::FleetOpsSidebar::DriverListing @title="Drivers" />`);

        assert.dom(PANEL_DROPDOWN_TRIGGER).doesNotExist();
    });

    test('each driver dropdown action delegates to the driver actions service', async function (assert) {
        this.store.result = [ANN];
        this.abilities.denied.add('fleet-ops delete driver');

        await render(hbs`<Layout::FleetOpsSidebar::DriverListing @title="Drivers" />`);

        const open = () => click('.driver-nav-item .next-nav-item-dropdown-button');

        await open();
        assert.deepEqual(menuLabels(), ['View Driver Details', 'Edit Driver Details', 'Assign Order to Driver', 'Assign Vehicle to Driver', 'Locate Driver on Map', 'Delete Driver']);

        await clickMenuItem('View Driver Details');
        await open();
        await clickMenuItem('Edit Driver Details');
        await open();
        await clickMenuItem('Assign Order to Driver');
        await open();
        await clickMenuItem('Assign Vehicle to Driver');
        await open();
        await clickMenuItem('Delete Driver');

        assert.deepEqual(this.driverActions.calls, [
            { method: 'panel.view', args: [ANN] },
            { method: 'panel.edit', args: [ANN, { useDefaultSaveTask: true }] },
            { method: 'assignOrder', args: [ANN] },
            { method: 'assignVehicle', args: [ANN] },
            { method: 'delete', args: [ANN] },
        ]);
    });

    test('locating a driver uses the map service away from the operations dashboard', async function (assert) {
        this.store.result = [ANN];
        this.hostRouter.currentRouteName = 'console.fleet-ops.management.drivers.index';

        await render(hbs`<Layout::FleetOpsSidebar::DriverListing @title="Drivers" />`);
        await click('.driver-nav-item .next-nav-item-dropdown-button');
        await clickMenuItem('Locate Driver on Map');

        assert.deepEqual(this.driverActions.calls, [{ method: 'locate', args: [ANN] }]);
        assert.deepEqual(this.hostRouter.calls, [], 'no transition is needed');
    });

    test('locating a driver on the operations dashboard focuses it on the live map instead', async function (assert) {
        this.store.result = [ANN];
        this.mapManager.ready = true;
        this.hostRouter.currentRouteName = 'console.fleet-ops.operations.orders.index.details';

        await render(hbs`<Layout::FleetOpsSidebar::DriverListing @title="Drivers" />`);
        await click('.driver-nav-item .next-nav-item-dropdown-button');
        await clickMenuItem('Locate Driver on Map');

        assert.deepEqual(this.driverActions.calls, [], 'the locate action is bypassed');
        assert.strictEqual(this.hostRouter.calls[0].args[0], ORDERS_ROUTE);
        assert.strictEqual(this.mapManager.focusCalls[0].resource, ANN);
    });
});
