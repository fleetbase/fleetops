import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, fillIn, findAll, render, settled, triggerEvent, waitUntil } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';
import StubEventedService from 'dummy/utils/stub-evented-service';

/**
 * Complements operations-monitor-test.js (sorting, id-linked fleet children, text filter) with the
 * rest of the component: live-map sources, every tab's empty state and action, fleet expansion and
 * filtered fleet rows, the row dropdown actions, locate flows, store normalisation, reload events
 * and the list-height layout observers.
 */

const ORDERS_ROUTE = 'console.fleet-ops.operations.orders.index';
const TAB = (id) => `[data-test-operations-monitor-tab="${id}"]`;
const FILTER = '[data-test-operations-monitor-filter]';
const LIST = '[data-test-operations-monitor-list]';
const EMPTY = '[data-test-operations-monitor-empty-state]';
const EMPTY_ACTION = '.fleet-ops-operations-monitor-empty-action';
const ROW = '.fleet-ops-operations-monitor-row';
const CHILD_ROW = '.fleet-ops-operations-monitor-child-row';
const MENU_ITEM = '.next-dd-item';

class RecordingService extends Service {
    calls = [];

    record(method, args) {
        this.calls.push({ method, args });
    }
}

class StoreStub extends RecordingService {
    records = new Map();
    pushPayloadThrows = false;

    pushPayload(modelName, payload) {
        this.record('pushPayload', [modelName, payload]);

        if (this.pushPayloadThrows) {
            throw new Error('pushPayload is not supported by this host');
        }

        const resource = payload[modelName];
        const keys = [resource.id, resource.public_id].filter(Boolean);

        for (const key of keys) {
            this.records.set(`${modelName}:${key}`, this.records.get(`${modelName}:${key}`) ?? resource);
        }
    }

    peekRecord(modelName, id) {
        return this.records.get(`${modelName}:${id}`) ?? null;
    }
}

class FetchStub extends Service {
    calls = 0;
    response = { drivers: [], vehicles: [], fleets: [] };
    error = null;

    get(...args) {
        this.calls++;
        this.lastArgs = args;

        if (this.error) {
            return Promise.reject(this.error);
        }

        return Promise.resolve(this.response);
    }
}

class MapManagerStub extends Service {
    livemap = null;
    waits = [];
    focusCalls = [];

    waitForMap(options) {
        this.waits.push(options);
        return Promise.resolve();
    }

    focusResource(resource, zoom, options) {
        this.focusCalls.push({ resource, zoom, options });
    }
}

class DriverActionsStub extends RecordingService {
    panel = {
        create: (...args) => this.record('panel.create', args),
        view: (...args) => this.record('panel.view', args),
        edit: (...args) => this.record('panel.edit', args),
    };

    assignOrder(...args) {
        this.record('assignOrder', args);
    }

    assignVehicle(...args) {
        this.record('assignVehicle', args);
    }

    delete(...args) {
        this.record('delete', args);
    }
}

class VehicleActionsStub extends RecordingService {
    panel = {
        create: (...args) => this.record('panel.create', args),
        view: (...args) => this.record('panel.view', args),
        edit: (...args) => this.record('panel.edit', args),
    };

    delete(...args) {
        this.record('delete', args);
    }
}

class FleetActionsStub extends RecordingService {
    panel = {
        create: (...args) => this.record('panel.create', args),
        view: (...args) => this.record('panel.view', args),
    };

    assignDriver(...args) {
        this.record('assignDriver', args);
    }

    assignVehicle(...args) {
        this.record('assignVehicle', args);
    }
}

class NotificationsStub extends RecordingService {
    serverError(error) {
        this.record('serverError', [error]);
    }
}

class UniverseStub extends StubEventedService {}

const ANN = { id: 'driver_1', public_id: 'driver_1', name: 'Ann', online: true, status: 'active', vehicle_name: 'Van 1' };
const BOB = { id: 'driver_2', public_id: 'driver_2', display_name: 'Bob', online: false, status: 'inactive' };
const VAN = { id: 'vehicle_1', public_id: 'vehicle_1', display_name: 'Van 1', online: true, status: 'active', driver_name: 'Ann', plate_number: 'VAN-001' };
const TRUCK = { id: 'vehicle_2', public_id: 'vehicle_2', name: 'Truck 2', online: false, status: 'inactive', vin: 'VIN0002' };

function fleetPayload() {
    return {
        drivers: [ANN, BOB],
        vehicles: [VAN, TRUCK],
        fleets: [
            {
                id: 'fleet_1',
                public_id: 'fleet_1',
                name: 'North Fleet',
                driver_ids: ['driver_1'],
                vehicle_ids: ['vehicle_1'],
                drivers_count: 1,
                vehicles_count: 1,
                subfleets: [{ id: 'fleet_2', public_id: 'fleet_2', name: 'North Subfleet', driver_ids: ['driver_2'], vehicle_ids: [], driver_count: 1, vehicle_count: 0, subfleets: [] }],
            },
            { id: 'fleet_3', public_id: 'fleet_3', name: 'Empty Fleet', driver_ids: [], vehicle_ids: [], subfleets: [] },
        ],
    };
}

function rowLabels() {
    return findAll(`${LIST} ${ROW}, ${LIST} ${CHILD_ROW}`).map((row) => row.querySelector('.leading-5, .leading-4').textContent.trim());
}

async function openRowMenu(index = 0) {
    await click(findAll(`${LIST} ${ROW} .ember-basic-dropdown-trigger`)[index]);
}

async function clickMenuItem(label) {
    const item = findAll(MENU_ITEM).find((element) => element.textContent.trim() === label);

    if (!item) {
        throw new Error(
            `no menu item "${label}" (have: ${findAll(MENU_ITEM)
                .map((element) => element.textContent.trim())
                .join(', ')})`
        );
    }

    await click(item);
}

module('Integration | Component | layout/fleet-ops-sidebar/operations-monitor (actions and layout)', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:store', StoreStub);
        this.owner.register('service:fetch', FetchStub);
        this.owner.register('service:universe', UniverseStub);
        this.owner.register('service:map-manager', MapManagerStub);
        this.owner.register('service:notifications', NotificationsStub);
        this.owner.register('service:driver-actions', DriverActionsStub);
        this.owner.register('service:vehicle-actions', VehicleActionsStub);
        this.owner.register('service:fleet-actions', FleetActionsStub);

        this.store = this.owner.lookup('service:store');
        this.fetch = this.owner.lookup('service:fetch');
        this.universe = this.owner.lookup('service:universe');
        this.mapManager = this.owner.lookup('service:map-manager');
        this.notifications = this.owner.lookup('service:notifications');
        this.driverActions = this.owner.lookup('service:driver-actions');
        this.vehicleActions = this.owner.lookup('service:vehicle-actions');
        this.fleetActions = this.owner.lookup('service:fleet-actions');
        this.hostRouter = this.owner.lookup('service:host-router');

        this.fetch.response = fleetPayload();
        this.renderMonitor = async () => {
            await render(hbs`<Layout::FleetOpsSidebar::OperationsMonitor />`);
            await waitUntil(() => this.fetch.calls > 0 && !this.element.textContent.includes('0 drivers online'), { timeout: 2000 }).catch(() => {});
        };
    });

    test('it loads the monitor payload and normalises records through the store', async function (assert) {
        await this.renderMonitor();

        assert.deepEqual(this.fetch.lastArgs, ['fleet-ops/live/operations-monitor', {}, { namespace: 'int/v1' }]);
        assert.deepEqual(
            this.store.calls.map((call) => `${call.method}:${call.args[0]}:${call.args[1][call.args[0]].id}`),
            [
                'pushPayload:driver:driver_1',
                'pushPayload:driver:driver_2',
                'pushPayload:vehicle:vehicle_1',
                'pushPayload:vehicle:vehicle_2',
                'pushPayload:fleet:fleet_1',
                'pushPayload:fleet:fleet_2',
                'pushPayload:fleet:fleet_3',
            ],
            'every driver, vehicle, fleet and subfleet is pushed into the store'
        );
        assert.dom('.fleet-ops-operations-monitor').includesText('1 drivers online').includesText('1 vehicles online');
        assert.deepEqual(rowLabels(), ['North Fleet', 'North Subfleet', 'Bob', 'Ann', 'Van 1', 'Empty Fleet'], 'fleets start fully expanded with subfleets, then drivers, then vehicles');
        assert.dom(`${LIST} ${ROW}`).includesText('1 drivers - 1 vehicles');
    });

    test('it keeps working when the host store cannot push payloads', async function (assert) {
        this.store.pushPayloadThrows = true;

        await this.renderMonitor();

        assert.deepEqual(rowLabels().slice(0, 2), ['North Fleet', 'North Subfleet'], 'raw resources are used as-is');
    });

    test('it stores fleet membership on record-like fleets returned by the store', async function (assert) {
        const record = {
            id: 'fleet_1',
            public_id: 'fleet_1',
            name: 'Record Fleet',
            sets: {},
            set(key, value) {
                this[key] = value;
                this.sets[key] = value;
            },
        };
        this.store.records.set('fleet:fleet_1', record);
        this.fetch.response = {
            drivers: [ANN],
            vehicles: [],
            fleets: [{ id: 'fleet_1', name: 'North Fleet', driver_ids: ['driver_1'], vehicle_ids: { not: 'a list' }, subfleets: null }],
        };

        await this.renderMonitor();

        assert.deepEqual(
            record.sets,
            { driver_ids: ['driver_1'], vehicle_ids: [], subfleets: [] },
            'ids and subfleets are copied onto the record; missing or malformed lists become empty arrays'
        );
        assert.deepEqual(rowLabels(), ['Record Fleet', 'Ann']);
    });

    test('fleets are keyed by whichever identifier they carry, and a null fleet entry is tolerated', async function (assert) {
        this.fetch.response = {
            drivers: [],
            vehicles: [],
            fleets: [{ uuid: 'u1', name: 'Uuid Fleet', subfleets: [] }, { public_id: 'p1', name: 'Public Fleet', subfleets: [] }, { name: 'Name Fleet', subfleets: [] }, null],
        };

        await render(hbs`<Layout::FleetOpsSidebar::OperationsMonitor />`);

        assert.deepEqual(rowLabels(), ['Uuid Fleet', 'Public Fleet', 'Name Fleet', '']);

        await click(findAll(`${LIST} ${ROW} button`)[1]);
        assert.deepEqual(rowLabels(), ['Uuid Fleet', 'Public Fleet', 'Name Fleet', ''], 'collapsing a childless fleet changes nothing visible');
    });

    test('it reports a failed monitor request', async function (assert) {
        this.fetch.error = new Error('offline');

        await render(hbs`<Layout::FleetOpsSidebar::OperationsMonitor />`);

        assert.strictEqual(this.notifications.calls.length, 1);
        assert.strictEqual(this.notifications.calls[0].args[0].message, 'offline');
        assert.dom(EMPTY).includesText('No fleets yet');
    });

    test('it reloads on every fleet, driver and vehicle change announced by the universe', async function (assert) {
        await this.renderMonitor();
        assert.strictEqual(this.fetch.calls, 1);

        for (const event of [
            'fleet-ops.driver.saved',
            'fleet-ops.vehicle.saved',
            'fleet-ops.fleet.vehicle_assigned',
            'fleet-ops.fleet.vehicle_unassigned',
            'fleet-ops.fleet.driver_assigned',
            'fleet-ops.fleet.driver_unassigned',
        ]) {
            this.universe.trigger(event);
            await settled();
        }

        assert.strictEqual(this.fetch.calls, 7);
    });

    test('live map resources take precedence over the fallback payload', async function (assert) {
        this.mapManager.livemap = {
            drivers: [{ id: 'live_driver', public_id: 'live_driver', name: 'Live Driver', online: true }],
            vehicles: [{ id: 'live_vehicle', public_id: 'live_vehicle', display_name: 'Live Van', online: false }],
        };

        await this.renderMonitor();
        assert.dom('.fleet-ops-operations-monitor').includesText('1 drivers online').includesText('0 vehicles online');

        await click(TAB('drivers'));
        assert.deepEqual(rowLabels(), ['Live Driver']);

        await click(TAB('vehicles'));
        assert.deepEqual(rowLabels(), ['Live Van']);
    });

    test('the text filter narrows drivers and vehicles by name, public id and status', async function (assert) {
        await this.renderMonitor();

        await click(TAB('drivers'));
        assert.deepEqual(rowLabels(), ['Ann', 'Bob'], 'online first');

        await fillIn(FILTER, 'inactive');
        assert.deepEqual(rowLabels(), ['Bob']);

        await fillIn(FILTER, 'DRIVER_1');
        assert.deepEqual(rowLabels(), ['Ann'], 'matching is case-insensitive');

        await fillIn(FILTER, 'nobody');
        assert.dom(EMPTY).includesText('No resources match this search');
        await click(EMPTY_ACTION);
        assert.dom(FILTER).hasValue('');
        assert.deepEqual(rowLabels(), ['Ann', 'Bob'], 'the empty-state action clears the filter');

        await click(TAB('vehicles'));
        await fillIn(FILTER, 'truck');
        assert.deepEqual(rowLabels(), ['Truck 2']);
    });

    test('offline resources without a display name still sort deterministically', async function (assert) {
        this.fetch.response = {
            drivers: [
                { id: 'd_z', public_id: 'd_z', name: 'Zed', online: false },
                { id: 'd_a', public_id: 'd_a', name: 'Amy', online: false },
                { id: 'd_blank', online: false },
                { id: 'd_blank2', online: false },
            ],
            vehicles: [],
            fleets: [],
        };

        await render(hbs`<Layout::FleetOpsSidebar::OperationsMonitor />`);

        await click(TAB('drivers'));

        assert.deepEqual(rowLabels(), ['', '', 'Amy', 'Zed'], 'ties on online state fall back to name order; nameless resources sort first');
    });

    test('each tab offers a create action when it is empty', async function (assert) {
        this.fetch.response = { drivers: [], vehicles: [], fleets: [] };

        await render(hbs`<Layout::FleetOpsSidebar::OperationsMonitor />`);

        assert.dom(EMPTY).includesText('No fleets yet');
        await click(EMPTY_ACTION);
        assert.deepEqual(this.fleetActions.calls, [{ method: 'panel.create', args: [] }]);

        await click(TAB('drivers'));
        assert.dom(EMPTY).includesText('No drivers yet');
        await click(EMPTY_ACTION);
        assert.deepEqual(this.driverActions.calls, [{ method: 'panel.create', args: [] }]);

        await click(TAB('vehicles'));
        assert.dom(EMPTY).includesText('No vehicles yet');
        await click(EMPTY_ACTION);
        assert.deepEqual(this.vehicleActions.calls, [{ method: 'panel.create', args: [] }]);
    });

    test('fleets collapse and expand, and embedded members are used before id links', async function (assert) {
        this.fetch.response = {
            drivers: [],
            vehicles: [],
            fleets: [
                {
                    id: 'fleet_e',
                    name: 'Embedded Fleet',
                    drivers: { toArray: () => [{ id: 'ed', name: 'Embedded Driver', online: true }] },
                    vehicles: [{ id: 'ev', display_name: 'Embedded Van', online: false }],
                    subfleets: [],
                },
            ],
        };

        await render(hbs`<Layout::FleetOpsSidebar::OperationsMonitor />`);

        assert.deepEqual(rowLabels(), ['Embedded Fleet', 'Embedded Driver', 'Embedded Van']);
        assert.dom(`${LIST} ${ROW}`).includesText('1 drivers - 1 vehicles', 'counts fall back to the embedded members');

        await click(`${LIST} ${ROW} button`);
        assert.deepEqual(rowLabels(), ['Embedded Fleet'], 'collapsed');

        await click(`${LIST} ${ROW} button`);
        assert.deepEqual(rowLabels(), ['Embedded Fleet', 'Embedded Driver', 'Embedded Van'], 'expanded again');
    });

    test('filtering fleets keeps matching fleets whole and prunes non-matching branches', async function (assert) {
        await this.renderMonitor();

        await fillIn(FILTER, 'north fleet');
        assert.deepEqual(rowLabels(), ['North Fleet', 'North Subfleet', 'Bob', 'Ann', 'Van 1'], 'a matching fleet shows every descendant');

        await fillIn(FILTER, 'subfleet');
        assert.deepEqual(rowLabels(), ['North Fleet', 'North Subfleet', 'Bob'], 'a matching subfleet keeps its parent as context');

        await fillIn(FILTER, 'van-001');
        assert.deepEqual(rowLabels(), ['North Fleet', 'Van 1'], 'a matching vehicle keeps only its fleet');

        await fillIn(FILTER, 'inactive');
        assert.deepEqual(rowLabels(), ['North Fleet', 'North Subfleet', 'Bob'], 'a matching driver inside a subfleet keeps both ancestors');

        await fillIn(FILTER, '1 drivers - 1 vehicles');
        assert.deepEqual(rowLabels().slice(0, 1), ['North Fleet'], 'the counts subtitle is searchable too');

        await fillIn(FILTER, 'nothing here');
        assert.dom(EMPTY).includesText('No resources match this search');
    });

    test('driver row actions delegate to the driver actions service', async function (assert) {
        this.mapManager.livemap = { drivers: [], vehicles: [] };
        await this.renderMonitor();
        await click(TAB('drivers'));

        for (const label of ['View details', 'Edit details', 'Assign order', 'Assign vehicle', 'Delete driver']) {
            await openRowMenu(0);
            await clickMenuItem(label);
        }

        assert.deepEqual(this.driverActions.calls, [
            { method: 'panel.view', args: [ANN] },
            { method: 'panel.edit', args: [ANN, { useDefaultSaveTask: true }] },
            { method: 'assignOrder', args: [ANN] },
            { method: 'assignVehicle', args: [ANN] },
            { method: 'delete', args: [ANN] },
        ]);
    });

    test('vehicle row actions delegate to the vehicle actions service', async function (assert) {
        await this.renderMonitor();
        await click(TAB('vehicles'));

        for (const label of ['View details', 'Edit details', 'Delete vehicle']) {
            await openRowMenu(0);
            await clickMenuItem(label);
        }

        assert.deepEqual(this.vehicleActions.calls, [
            { method: 'panel.view', args: [VAN] },
            { method: 'panel.edit', args: [VAN, { useDefaultSaveTask: true }] },
            { method: 'delete', args: [VAN] },
        ]);
    });

    test('fleet row actions delegate to the fleet actions service', async function (assert) {
        await this.renderMonitor();

        for (const label of ['View details', 'Assign driver', 'Assign vehicle']) {
            await openRowMenu(0);
            await clickMenuItem(label);
        }

        const northFleet = this.fleetActions.calls[0].args[0];
        assert.strictEqual(northFleet.name, 'North Fleet');
        assert.deepEqual(
            this.fleetActions.calls.map((call) => call.method),
            ['panel.view', 'assignDriver', 'assignVehicle']
        );
    });

    test('locating a driver moves to the live map, focuses the driver and opens its panel once the map settles', async function (assert) {
        await this.renderMonitor();
        await click(TAB('drivers'));
        await click(`${LIST} ${ROW} button`);

        assert.deepEqual(this.hostRouter.calls, [{ method: 'transitionTo', args: [ORDERS_ROUTE, { queryParams: { layout: 'map' } }] }]);
        assert.deepEqual(this.mapManager.waits, [{ timeoutMs: 8000 }]);

        const [{ resource, zoom, options }] = this.mapManager.focusCalls;
        assert.strictEqual(resource, ANN);
        assert.strictEqual(zoom, 16);
        assert.deepEqual(options.paddingBottomRight, [300, 200]);

        options.moveend();
        assert.deepEqual(this.driverActions.calls, [{ method: 'panel.view', args: [ANN, { closeOnTransition: true }] }]);
    });

    test('locating a vehicle from its menu survives a rejected transition', async function (assert) {
        this.hostRouter.transitionTo = () => Promise.reject(new Error('in flight'));
        await this.renderMonitor();
        await click(TAB('vehicles'));
        await openRowMenu(0);
        await clickMenuItem('Locate on map');

        const [{ resource, options }] = this.mapManager.focusCalls;
        assert.strictEqual(resource, VAN);

        options.moveend();
        assert.deepEqual(this.vehicleActions.calls, [{ method: 'panel.view', args: [VAN, { closeOnTransition: true }] }]);
    });

    test('locating a driver from a fleet child row and from the driver menu both focus the map', async function (assert) {
        await this.renderMonitor();

        await click(findAll(`${LIST} ${CHILD_ROW}`)[1]);
        assert.strictEqual(this.mapManager.focusCalls[0].resource, ANN, 'the fleet child row locates Ann');

        await click(findAll(`${LIST} ${CHILD_ROW}`)[2]);
        assert.strictEqual(this.mapManager.focusCalls[1].resource, VAN, 'the fleet child row locates Van 1');

        await click(TAB('drivers'));
        await openRowMenu(0);
        await clickMenuItem('Locate on map');
        assert.strictEqual(this.mapManager.focusCalls[2].resource, ANN);
    });

    test('the list height follows its sidebar boundary and re-measures on resize', async function (assert) {
        await render(hbs`
            <div class="next-sidebar-navigator">
                <div class="next-sidebar-content-inner">
                    <Layout::FleetOpsSidebar::OperationsMonitor />
                </div>
            </div>
        `);
        const inner = this.element.querySelector('.next-sidebar-content-inner');
        inner.style.height = '600px';
        inner.style.position = 'relative';
        await triggerEvent(window, 'resize');

        const list = this.element.querySelector(LIST);
        await waitUntil(() => list.style.getPropertyValue('--fleet-ops-operations-monitor-list-height'));

        const first = list.style.getPropertyValue('--fleet-ops-operations-monitor-list-height');
        assert.ok(/^\d+px$/.test(first), `a list height is set (${first})`);

        inner.style.height = '20px';
        await triggerEvent(window, 'resize');
        await waitUntil(() => list.style.getPropertyValue('--fleet-ops-operations-monitor-list-height') === '128px');
        assert.strictEqual(list.style.getPropertyValue('--fleet-ops-operations-monitor-list-height'), '128px', 'never shrinks below the minimum');
    });

    test('the list height falls back to the sidebar content, then to the parent element', async function (assert) {
        await render(hbs`
            <div class="next-sidebar-content">
                <Layout::FleetOpsSidebar::OperationsMonitor />
            </div>
        `);
        const content = this.element.querySelector('.next-sidebar-content');
        content.style.height = '400px';
        content.style.position = 'relative';
        await triggerEvent(window, 'resize');

        const list = this.element.querySelector(LIST);
        await waitUntil(() => list.style.getPropertyValue('--fleet-ops-operations-monitor-list-height'));
        assert.ok(list.style.getPropertyValue('--fleet-ops-operations-monitor-list-height'), 'measured against .next-sidebar-content');

        await render(hbs`<Layout::FleetOpsSidebar::OperationsMonitor />`);

        const bare = this.element.querySelector(LIST);
        await waitUntil(() => bare.style.getPropertyValue('--fleet-ops-operations-monitor-list-height'));
        assert.ok(bare.style.getPropertyValue('--fleet-ops-operations-monitor-list-height'), 'measured against the parent element');
    });
});
