import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, findAll, render, settled } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';
import { setComponentTemplate } from '@ember/component';
import templateOnly from '@ember/component/template-only';
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

class VehicleActionsStub extends Service {
    calls = [];

    panel = {
        view: (...args) => this.calls.push({ method: 'panel.view', args }),
        edit: (...args) => this.calls.push({ method: 'panel.edit', args }),
    };

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

const VAN = { id: 'vehicle_1', display_name: 'Van 1' };
const NORTH = { id: 'fleet_1', name: 'North Fleet', vehicle: VAN };
const SOUTH = { id: 'fleet_2', name: 'South Fleet', vehicle: VAN };

// The real FleetListingPanel is a large tree of its own (covered by its own suite). This stand-in
// exposes exactly the surface the listing hands it: the fleet, the vehicle click handler and the
// per-vehicle dropdown actions.
const FleetListingPanelStub = setComponentTemplate(
    hbs`
        <div class="fleet-panel-stub" data-fleet={{@fleet.id}} data-depth={{@depth}} data-open={{if @open "yes" "no"}}>
            <span class="fleet-panel-stub-name">{{@fleet.name}}</span>
            <button type="button" class="fleet-panel-stub-vehicle" {{on "click" (fn @onVehicleClicked @fleet.vehicle)}}>{{@fleet.vehicle.display_name}}</button>
            {{#each @itemDropdownButtonActions as |action|}}
                {{#if action.onClick}}
                    <button type="button" class="fleet-panel-stub-action" {{on "click" (fn action.onClick @fleet.vehicle)}}>{{action.label}}</button>
                {{else}}
                    <hr class="fleet-panel-stub-separator" />
                {{/if}}
            {{/each}}
        </div>
    `,
    templateOnly()
);

function actionLabels() {
    return findAll('.fleet-panel-stub-action').map((element) => element.textContent.trim());
}

async function clickAction(label) {
    const button = findAll('.fleet-panel-stub-action').find((element) => element.textContent.trim() === label);

    if (!button) {
        throw new Error(`no action labelled "${label}" (have: ${actionLabels().join(', ')})`);
    }

    await click(button);
}

module('Integration | Component | layout/fleet-ops-sidebar/fleet-listing', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:store', StoreStub);
        this.owner.register('service:vehicle-actions', VehicleActionsStub);
        this.owner.register('service:map-manager', MapManagerStub);
        this.owner.register('service:notifications', NotificationsStub);
        this.owner.register('service:abilities', AbilitiesStub);
        this.owner.register('service:universe', UniverseStub);
        this.owner.register('component:fleet-listing-panel', FleetListingPanelStub);

        this.store = this.owner.lookup('service:store');
        this.vehicleActions = this.owner.lookup('service:vehicle-actions');
        this.mapManager = this.owner.lookup('service:map-manager');
        this.notifications = this.owner.lookup('service:notifications');
        this.abilities = this.owner.lookup('service:abilities');
        this.universe = this.owner.lookup('service:universe');
        this.hostRouter = this.owner.lookup('service:host-router');

        this.store.result = [NORTH, SOUTH];
    });

    test('it lists parent fleets with their vehicles and subfleets loaded', async function (assert) {
        await render(hbs`<Layout::FleetOpsSidebar::FleetListing @title="Fleets" @icon="user-group" />`);

        assert.deepEqual(this.store.queries, [{ modelName: 'fleet', params: { with: ['vehicles', 'subfleets'], parents_only: true } }]);
        assert.dom('.next-fleet-summary').includesText('Fleets');
        assert.dom('.fleet-panel-stub').exists({ count: 2 });
        assert.dom('.fleet-panel-stub[data-fleet="fleet_1"]').hasAttribute('data-depth', '1').hasAttribute('data-open', 'yes').includesText('North Fleet');
        assert.deepEqual(actionLabels().slice(0, 3), ['View Vehicle Details', 'Edit Vehicle Details', 'Locate Vehicle on Map'], 'vehicle actions are handed to each fleet panel');
        assert.dom('.fleet-panel-stub[data-fleet="fleet_1"] .fleet-panel-stub-separator').exists({ count: 1 });
    });

    test('it does not query fleets without the list permission', async function (assert) {
        this.abilities.denied.add('fleet-ops list fleet');

        await render(hbs`<Layout::FleetOpsSidebar::FleetListing @title="Fleets" />`);

        assert.deepEqual(this.store.queries, []);
        assert.dom('.fleet-panel-stub').doesNotExist();
    });

    test('it reports a failed fleet query', async function (assert) {
        this.store.error = new Error('boom');

        await render(hbs`<Layout::FleetOpsSidebar::FleetListing @title="Fleets" />`);

        assert.strictEqual(this.notifications.errors.length, 1);
        assert.strictEqual(this.notifications.errors[0].message, 'boom');
        assert.dom('.fleet-panel-stub').doesNotExist();
    });

    test('it reloads whenever a vehicle or driver is assigned to or removed from a fleet', async function (assert) {
        await render(hbs`<Layout::FleetOpsSidebar::FleetListing @title="Fleets" />`);
        assert.strictEqual(this.store.queries.length, 1);

        for (const event of ['fleet-ops.fleet.vehicle_assigned', 'fleet-ops.fleet.vehicle_unassigned', 'fleet-ops.fleet.driver_assigned', 'fleet-ops.fleet.driver_unassigned']) {
            this.universe.trigger(event);
            await settled();
        }

        assert.strictEqual(this.store.queries.length, 5, 'each of the four fleet membership events triggers a reload');
    });

    test('clicking a vehicle jumps to the live map and focuses it once the map is ready', async function (assert) {
        this.mapManager.ready = true;
        this.set('focused', []);
        this.set('onFocusVehicle', (vehicle) => this.focused.push(vehicle));

        await render(hbs`<Layout::FleetOpsSidebar::FleetListing @title="Fleets" @onFocusVehicle={{this.onFocusVehicle}} />`);
        await click('.fleet-panel-stub[data-fleet="fleet_1"] .fleet-panel-stub-vehicle');

        assert.deepEqual(this.hostRouter.calls, [{ method: 'transitionTo', args: [ORDERS_ROUTE, { queryParams: { layout: 'map' } }] }]);
        assert.deepEqual(this.mapManager.waits, [{ timeoutMs: 8000 }]);

        const [{ resource, zoom, options }] = this.mapManager.focusCalls;
        assert.strictEqual(resource, VAN);
        assert.strictEqual(zoom, 16);
        assert.deepEqual(options.paddingBottomRight, [300, 200]);
        assert.deepEqual(this.focused, [VAN]);

        options.moveend();
        assert.deepEqual(this.vehicleActions.calls, [{ method: 'panel.view', args: [VAN, { closeOnTransition: true }] }]);
    });

    test('clicking a vehicle defers focusing until the live map loads, even when the transition fails', async function (assert) {
        this.hostRouter.transitionTo = () => Promise.reject(new Error('blocked'));

        await render(hbs`<Layout::FleetOpsSidebar::FleetListing @title="Fleets" />`);
        await click('.fleet-panel-stub[data-fleet="fleet_2"] .fleet-panel-stub-vehicle');

        assert.strictEqual(this.mapManager.focusCalls.length, 0);

        this.mapManager.ready = true;
        this.universe.trigger('fleet-ops.live-map.loaded');
        await settled();

        assert.strictEqual(this.mapManager.focusCalls.length, 1);
        assert.strictEqual(this.mapManager.focusCalls[0].resource, VAN);
    });

    test('clicking the panel title transitions to @route, collapsing the panel only on the fleets index', async function (assert) {
        await render(hbs`<Layout::FleetOpsSidebar::FleetListing @title="Fleets" @route={{this.route}} />`);

        await click('.next-fleet-summary .next-content-panel-title-container');
        assert.deepEqual(this.hostRouter.calls, [], 'no @route, no transition');

        this.set('route', FLEETS_ROUTE);
        await click('.next-fleet-summary .next-content-panel-title-container');

        assert.deepEqual(this.hostRouter.calls, [{ method: 'transitionTo', args: [FLEETS_ROUTE] }]);
        assert.dom('.next-fleet-summary').hasClass('is-open');

        this.hostRouter.currentRouteName = FLEETS_ROUTE;
        await click('.next-fleet-summary .next-content-panel-title-container');

        assert.strictEqual(this.hostRouter.calls.length, 2);
        assert.dom('.next-fleet-summary').hasClass('is-closed', 'on the fleets index the title click also collapses the panel');
    });

    test('each vehicle dropdown action delegates to the vehicle actions service', async function (assert) {
        this.store.result = [NORTH];
        this.hostRouter.currentRouteName = 'console.fleet-ops.management.fleets.index';

        await render(hbs`<Layout::FleetOpsSidebar::FleetListing @title="Fleets" />`);

        await clickAction('View Vehicle Details');
        await clickAction('Edit Vehicle Details');
        await clickAction('Locate Vehicle on Map');
        await clickAction('Delete Vehicle');

        assert.deepEqual(this.vehicleActions.calls, [
            { method: 'panel.view', args: [VAN] },
            { method: 'panel.edit', args: [VAN, { useDefaultSaveTask: true }] },
            { method: 'locate', args: [VAN] },
            { method: 'delete', args: [VAN] },
        ]);
        assert.deepEqual(this.hostRouter.calls, [], 'locating away from the dashboard never transitions');
    });

    test('locating a vehicle on the operations dashboard focuses it on the live map instead', async function (assert) {
        this.store.result = [NORTH];
        this.mapManager.ready = true;
        this.hostRouter.currentRouteName = 'console.fleet-ops.operations.orders.index';

        await render(hbs`<Layout::FleetOpsSidebar::FleetListing @title="Fleets" />`);
        await clickAction('Locate Vehicle on Map');

        assert.deepEqual(this.vehicleActions.calls, [], 'the locate action is bypassed');
        assert.strictEqual(this.hostRouter.calls[0].args[0], ORDERS_ROUTE);
        assert.strictEqual(this.mapManager.focusCalls[0].resource, VAN);
    });
});
