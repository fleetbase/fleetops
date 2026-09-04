import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, render, settled, triggerEvent } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service, { inject as service } from '@ember/service';
import Component from '@glimmer/component';
import { setComponentTemplate } from '@ember/component';
import { helper } from '@ember/component/helper';
import templateOnly from '@ember/component/template-only';
import registerTemplateOnly from 'dummy/tests/helpers/register-template-only';

/**
 * The phase builder needs to hand the workbench a phase list the test chose, which a
 * template-only stand-in cannot do — so this one is a real component reading the list off a
 * service the test writes to.
 */
class PhaseBuilderStub extends Component {
    @service workbenchTestBed;
}

/**
 * `ember-leaflet` is not installed in this package, so `<LeafletMap>` and the `div-icon` / `icon` /
 * `point-to-coordinates` helpers the map block uses cannot resolve at all. These stand-ins yield
 * the same `layers` shape ember-leaflet does, which is what lets the block render — and the block
 * is where `tileSourceUrl`, `getOrderStops` and `waypointIconHtml` are reached from.
 */
function templateOnlyComponent(template) {
    return setComponentTemplate(template, templateOnly());
}

const LeafletTileStub = templateOnlyComponent(hbs`<div data-test-tile data-url={{@url}}></div>`);
const LeafletPopupStub = templateOnlyComponent(hbs`<div data-test-marker-popup>{{yield}}</div>`);
const LeafletTooltipStub = templateOnlyComponent(hbs`<div data-test-marker-tooltip>{{yield}}</div>`);
class LeafletMarkerStub extends Component {
    popupComponent = LeafletPopupStub;
    tooltipComponent = LeafletTooltipStub;
}
setComponentTemplate(
    hbs`<div data-test-marker data-lat={{@lat}} data-lng={{@lng}} data-icon-html={{@icon.html}} data-location={{@location}}>
        {{yield (hash popup=this.popupComponent tooltip=this.tooltipComponent)}}
    </div>`,
    LeafletMarkerStub
);

class LeafletMapStub extends Component {
    @service workbenchTestBed;

    tileComponent = LeafletTileStub;
    markerComponent = LeafletMarkerStub;

    /**
     * ember-leaflet calls `@onLoad` when the map mounts. A test that needs the map to arrive
     * *after* the orders — which is when `_mapCenteredOnOrders` matters — holds it back here.
     */
    registerLoad = (onLoad) => {
        const bed = this.workbenchTestBed;
        bed.loadMap = () => onLoad({ target: bed.mapInstance });
        if (!bed.deferMapLoad) {
            bed.loadMap();
        }
    };
}
setComponentTemplate(
    hbs`<div data-test-map {{did-insert (fn this.registerLoad @onLoad)}}>
        {{yield (hash tile=this.tileComponent marker=this.markerComponent)}}
    </div>`,
    LeafletMapStub
);
setComponentTemplate(
    hbs`<div data-test-phase-builder>
        <span data-test-phase-count>{{@phases.length}}</span>
        <span data-test-engine-count>{{@availableEngines.length}}</span>
        <button type="button" data-test-change-phases {{on "click" (fn @onPhasesChange this.workbenchTestBed.phases)}}></button>
        <button type="button" data-test-run-phases {{on "click" (fn @onRunPhases this.workbenchTestBed.phases)}}></button>
    </div>`,
    PhaseBuilderStub
);

/**
 * The workbench owns cross-cutting state and delegates every panel to a sub-component, so the
 * honest unit here is the workbench itself with those panels stubbed. The map is stubbed as a
 * non-yielding element: `ember-leaflet` is not installed in this package (DEFECTS #75), so
 * `<LeafletMap>` cannot resolve at all without a stand-in.
 */
function stubPanels(owner) {
    owner.register('component:leaflet-map', LeafletMapStub);
    // ember-leaflet's own helpers, reduced to what the workbench reads back off them.
    owner.register(
        'helper:div-icon',
        helper((positional, named) => named)
    );
    owner.register(
        'helper:icon',
        helper((positional, named) => named)
    );
    owner.register(
        'helper:point-to-coordinates',
        helper(([location]) => location)
    );
    owner.register('component:orchestrator/phase-builder', PhaseBuilderStub);
    registerTemplateOnly(
        owner,
        'orchestrator/card-fields-settings',
        hbs`<div data-test-card-fields><button type="button" data-test-card-fields-saved {{on "click" @onSaved}}></button></div>`
    );
    // Each stand-in drives the callbacks the workbench hands it, which is what the real panel
    // does — the wiring is the thing under test.
    registerTemplateOnly(
        owner,
        'orchestrator/order-pool',
        hbs`<div data-test-order-pool>
            <span data-test-order-count>{{@orders.length}}</span>
            <span data-test-selected-orders>{{@selectedOrderIds.size}}</span>
            <button type="button" data-test-toggle-order {{on "click" (fn @onToggleSelection (get @orders 0))}}></button>
            <button type="button" data-test-clear-orders {{on "click" @onClearSelection}}></button>
            <button type="button" data-test-open-import {{on "click" @onOpenImport}}></button>
            <div data-test-order-drag {{on "dragstart" (fn @onDragStart (get @orders 0))}}></div>
        </div>`
    );
    // The real PlanViewer calls each formatter the workbench hands it, so the stand-in does too —
    // that is the wiring under test, not an invention of the harness.
    registerTemplateOnly(
        owner,
        'orchestrator/plan-viewer',
        hbs`<div data-test-plan-viewer>
            <span data-test-run-message>{{@runMessage}}</span>
            <span data-test-vehicle-count>{{@planByVehicle.length}}</span>
            <span data-test-unassigned-count>{{@unassignedAfterRun.length}}</span>
            <span data-test-driver-phase>{{if @hasDriverPhase "yes" "no"}}</span>
            <span data-test-summary-duration>{{get (get (get @planByVehicle "0") "summary") "duration"}}</span>
            <span data-test-duration>{{@formatDuration 3725}}</span>
            <span data-test-short-duration>{{@formatDuration 300}}</span>
            <span data-test-no-duration>{{@formatDuration 0}}</span>
            <span data-test-distance>{{@formatDistance 4520}}</span>
            <span data-test-short-distance>{{@formatDistance 400}}</span>
            <span data-test-no-distance>{{@formatDistance 0}}</span>
            <span data-test-unix>{{@formatUnixTime 43200}}</span>
            <span data-test-unix-empty>{{@formatUnixTime 0}}</span>
            <span data-test-stop-label>{{@getStopLabel 0}}/{{@getStopLabel 3}}</span>
            <span data-test-window>{{@formatTimeWindow "2026-06-22T09:00:00.000Z" "2026-06-22T11:30:00.000Z"}}</span>
            <span data-test-window-open>{{@formatTimeWindow "2026-06-22T09:00:00.000Z" null}}</span>
            <span data-test-window-instant>{{@formatTimeWindow "2026-06-22T09:00:00.000Z" "2026-06-22T09:00:00.000Z"}}</span>
            <span data-test-window-none>{{@formatTimeWindow null null}}</span>
            <span data-test-window-unreadable>{{@formatTimeWindow "not a date" null}}</span>
            <button type="button" data-test-dismiss-message {{on "click" @onDismissMessage}}></button>
            <div data-test-drop-target {{on "dragover" @onDragOver}} {{on "drop" (fn @onDropOnVehicle "vehicle_1" "driver_1")}}></div>
            <div data-test-assigned-drag {{on "dragstart" (fn @onAssignedOrderDragStart (get (get (get (get @planByVehicle '0') 'orders') '0') 'order'))}}></div>
        </div>`
    );
    registerTemplateOnly(
        owner,
        'orchestrator/resource-panel',
        hbs`<div data-test-resource-panel>
            <span data-test-resource-counts>{{@vehicles.length}}/{{@drivers.length}}</span>
            <span data-test-selected-vehicles>{{@selectedVehicleIds.size}}</span>
            <span data-test-selected-drivers>{{@selectedDriverIds.size}}</span>
            <button type="button" data-test-toggle-vehicle {{on "click" (fn @onToggleVehicle (get @vehicles 0))}}></button>
            <button type="button" data-test-toggle-driver {{on "click" (fn @onToggleDriver (get @drivers 0))}}></button>
            <button type="button" data-test-clear-vehicles {{on "click" @onClearVehicles}}></button>
            <button type="button" data-test-clear-drivers {{on "click" @onClearDrivers}}></button>
        </div>`
    );
}

module('Integration | Component | orchestrator-workbench', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        const test = this;
        this.gets = [];
        this.posts = [];
        this.notified = [];

        // Every request the component makes, keyed by path, so a test can answer one of them
        // differently without restating the rest.
        this.getResponses = {
            'fleet-ops/orchestrator/orders': { orders: [] },
            'fleet-ops/orchestrator/engines': { engines: [{ id: 'vroom', name: 'VROOM' }] },
            'fleet-ops/settings/orchestrator-card-fields': { settings: { standard: ['tracking'] } },
        };
        this.postResponses = {
            'fleet-ops/orchestrator/run': { assignments: [] },
            'fleet-ops/orchestrator/commit': { committed: [] },
        };

        this.owner.register(
            'service:fetch',
            class extends Service {
                get(path, params) {
                    test.gets.push([path, params]);
                    const answer = test.getResponses[path];
                    return typeof answer === 'function' ? answer() : Promise.resolve(answer);
                }
                post(path, body) {
                    test.posts.push([path, body]);
                    const answer = test.postResponses[path];
                    return typeof answer === 'function' ? answer() : Promise.resolve(answer);
                }
            }
        );

        this.queried = [];
        this.storeResults = { vehicle: [], driver: [] };
        this.owner.register(
            'service:store',
            class extends Service {
                query(modelName, params) {
                    test.queried.push([modelName, params]);
                    const rows = test.storeResults[modelName];
                    return typeof rows === 'function' ? rows() : Promise.resolve({ toArray: () => rows });
                }
            }
        );

        this.owner.register(
            'service:notifications',
            class extends Service {
                success(message) {
                    test.notified.push(['success', message]);
                }
                serverError(error) {
                    test.notified.push(['serverError', error?.message ?? error]);
                }
                warning(message) {
                    test.notified.push(['warning', message]);
                }
            }
        );

        this.userLocation = Promise.resolve({ latitude: 51.5, longitude: -0.12 });
        this.owner.register(
            'service:location',
            class extends Service {
                getLatitude() {
                    return test.latitude ?? null;
                }
                getLongitude() {
                    return test.longitude ?? null;
                }
                getUserLocation() {
                    return test.userLocation;
                }
            }
        );

        this.modals = [];
        this.owner.register(
            'service:modals-manager',
            class extends Service {
                show(name, options) {
                    test.modals.push([name, options]);
                }
            }
        );

        this.routed = [];
        this.fitted = [];
        this.removedRoutes = [];
        this.routeHandleFor = (waypoints) => ({ route: { coordinates: waypoints } });
        this.owner.register(
            'service:map-manager',
            class extends Service {
                addRoutingControl(waypoints, options) {
                    test.routed.push([waypoints, options]);
                    return Promise.resolve(test.routeHandleFor(waypoints, options));
                }
                fitBounds(latlngs, options) {
                    test.fitted.push([latlngs, options]);
                }
                removeRoutingControl(handle) {
                    test.removedRoutes.push(handle);
                }
                setActiveProvider(provider) {
                    test.activeProvider = provider;
                }
                setMapInstance(map) {
                    test.mapInstance = map;
                }
            }
        );
        this.owner.register(
            'service:map-settings',
            class extends Service {
                getLeafletTileUrl(theme) {
                    return `https://tiles.example/${theme}/{z}/{x}/{y}.png`;
                }
            }
        );
        this.owner.register(
            'service:route-engine',
            class extends Service {
                getDisplayEngine(name) {
                    return { name };
                }
            }
        );
        this.owner.register('service:order-allocation', class extends Service {});

        this.owner.register('service:workbench-test-bed', class extends Service {});
        this.testBed = this.owner.lookup('service:workbench-test-bed');
        this.testBed.phases = [];
        this.mapViews = [];
        this.mapFits = [];
        this.testBed.mapInstance = {
            setView: (center, zoom) => test.mapViews.push([center, zoom]),
            fitBounds: (bounds, options) => test.mapFits.push([bounds, options]),
        };

        stubPanels(this.owner);
    });

    test('it resolves through the string-based component resolver', function (assert) {
        assert.ok(this.owner.factoryFor('component:orchestrator-workbench'));
    });

    test('it loads orders, vehicles, drivers, engines and card fields as it comes up', async function (assert) {
        this.getResponses['fleet-ops/orchestrator/orders'] = {
            orders: [{ public_id: 'order_1' }, { public_id: 'order_2' }],
        };
        this.storeResults.vehicle = [{ public_id: 'vehicle_1' }];
        this.storeResults.driver = [{ public_id: 'driver_1' }, { public_id: 'driver_2' }];

        await render(hbs`<OrchestratorWorkbench />`);

        assert.deepEqual(
            this.gets.map(([path]) => path).sort(),
            ['fleet-ops/orchestrator/engines', 'fleet-ops/orchestrator/orders', 'fleet-ops/settings/orchestrator-card-fields'],
            'each endpoint is asked once'
        );
        assert.deepEqual(this.gets.find(([path]) => path.endsWith('/orders'))[1], { unassigned: true, limit: 500 }, 'orders are asked for unassigned, in bulk');
        assert.deepEqual(
            this.queried,
            [
                ['vehicle', { limit: 300 }],
                ['driver', { limit: 300 }],
            ],
            'and the resources come from the store'
        );

        assert.dom('[data-test-order-count]').hasText('2', 'the pool is handed the orders');
        assert.dom('[data-test-resource-counts]').hasText('1/2', 'and the resource panel its vehicles and drivers');
        assert.dom('[data-test-plan-viewer]').doesNotExist('with no plan there is nothing to view');
    });

    test('a load that fails is reported rather than thrown', async function (assert) {
        this.getResponses['fleet-ops/orchestrator/orders'] = () => Promise.reject(new Error('orders are down'));
        this.storeResults.vehicle = () => Promise.reject(new Error('vehicles are down'));
        this.storeResults.driver = () => Promise.reject(new Error('drivers are down'));
        this.getResponses['fleet-ops/orchestrator/engines'] = () => Promise.reject(new Error('engines are down'));
        this.getResponses['fleet-ops/settings/orchestrator-card-fields'] = () => Promise.reject(new Error('settings are down'));

        await render(hbs`<OrchestratorWorkbench />`);

        assert.deepEqual(
            this.notified,
            [
                ['serverError', 'orders are down'],
                ['serverError', 'vehicles are down'],
                ['serverError', 'drivers are down'],
            ],
            'the three that notify do so once each'
        );
        assert.dom('[data-test-order-pool]').exists('and the workbench still renders');
    });

    test('the engine list and card fields fall back rather than surface an error', async function (assert) {
        this.getResponses['fleet-ops/orchestrator/engines'] = () => Promise.reject(new Error('engines are down'));
        this.getResponses['fleet-ops/settings/orchestrator-card-fields'] = { settings: null };

        await render(hbs`<OrchestratorWorkbench />`);

        assert.deepEqual(this.notified, [], 'neither one bothers the user — the built-in engine is enough to work with');
    });

    test('the phase builder and card-fields panels are toggled from the toolbar', async function (assert) {
        await render(hbs`<OrchestratorWorkbench />`);

        assert.dom('[data-test-phase-builder]').doesNotExist('both panels start closed');
        assert.dom('[data-test-card-fields]').doesNotExist();

        await click('button:has(.fa-list-ol)');
        assert.dom('[data-test-phase-builder]').exists('the phases button opens the builder');
        assert.dom('[data-test-card-fields]').doesNotExist('and leaves the other closed');

        await click('button:has(.fa-list-ol)');
        assert.dom('[data-test-phase-builder]').doesNotExist('a second press closes it again');

        await click('button:has(.fa-table-columns)');
        assert.dom('[data-test-card-fields]').exists('the card-fields button opens its own');

        await click('button:has(.fa-list-ol)');
        assert.dom('[data-test-phase-builder]').exists('and opening one closes the other');
        assert.dom('[data-test-card-fields]').doesNotExist();
    });

    test('both side panels collapse and come back', async function (assert) {
        await render(hbs`<OrchestratorWorkbench />`);

        assert.dom('.order-pool-panel, .driver-panel').exists({ count: 2 });
        assert.dom('.driver-panel').doesNotHaveClass('w-0', 'both panels start open');

        await click('.workbench-panel-toggle.border-l');
        assert.dom('.driver-panel').hasClass('w-0', 'the right toggle collapses it');

        await click('.workbench-panel-toggle.border-l');
        assert.dom('.driver-panel').doesNotHaveClass('w-0', 'and a second press brings it back');
    });

    test('a run proposes a plan, draws its routes and offers to commit it', async function (assert) {
        this.getResponses['fleet-ops/orchestrator/orders'] = {
            orders: [
                {
                    public_id: 'order_1',
                    payload: { pickup: { location: { coordinates: [103.8, 1.3] }, address: 'Depot' }, dropoff: { location: { coordinates: [103.9, 1.4] }, address: 'Site' } },
                },
                {
                    public_id: 'order_2',
                    payload: { pickup: { location: { coordinates: [103.7, 1.2] }, address: 'Yard' }, dropoff: { location: { coordinates: [103.6, 1.1] }, address: 'Port' } },
                },
            ],
        };
        this.storeResults.vehicle = [{ public_id: 'vehicle_1' }];
        this.storeResults.driver = [{ public_id: 'driver_1', vehicle_id: 'vehicle_1' }];
        this.postResponses['fleet-ops/orchestrator/run'] = {
            assignments: [
                { order_id: 'order_1', vehicle_id: 'vehicle_1', driver_id: 'driver_1', sequence: 2, route_duration: 900, route_distance: 5400 },
                { order_id: 'order_2', vehicle_id: 'vehicle_1', driver_id: 'driver_1', sequence: 1 },
            ],
            unassigned: ['order_9'],
            message: 'Two of three placed',
        };

        await render(hbs`<OrchestratorWorkbench />`);
        await click('button.btn-primary');

        const [path, payload] = this.posts.at(-1);
        assert.strictEqual(path, 'fleet-ops/orchestrator/run');
        assert.deepEqual(payload.order_ids, ['order_1', 'order_2'], 'with nothing selected, every unassigned order is offered');
        assert.deepEqual(payload.vehicle_ids, ['vehicle_1'], 'and every available vehicle');
        assert.strictEqual(payload.mode, 'allocate', 'the legacy phase is used when none are configured');
        assert.deepEqual(payload.order_statuses, ['created']);
        assert.deepEqual(payload.prior_assignments, [], 'the first phase has nothing to build on');
        assert.notOk('driver_ids' in payload, 'and no drivers are pinned');

        assert.dom('[data-test-plan-viewer]').exists('the plan viewer replaces the resource panel');
        assert.dom('[data-test-resource-panel]').doesNotExist();
        assert.dom('[data-test-run-message]').hasText('Two of three placed');
        assert.dom('[data-test-vehicle-count]').hasText('1', 'the plan is grouped into one vehicle');
        assert.dom('[data-test-unassigned-count]').hasText('1');
        assert.dom('[data-test-driver-phase]').hasText('no', 'an allocate phase is not a driver phase');

        assert.strictEqual(this.routed.length, 1, 'a routing control is drawn for the vehicle');
        const [waypoints, routeOptions] = this.routed[0];
        assert.deepEqual(
            waypoints,
            [
                [1.2, 103.7],
                [1.1, 103.6],
                [1.3, 103.8],
                [1.4, 103.9],
            ],
            'threaded through both orders in sequence order, pickup then dropoff'
        );
        assert.strictEqual(routeOptions.tag, 'orchestrator:vehicle_1');
        assert.dom('[data-test-summary-duration]').hasText('900', 'the run timings are carried into the group summary');
        assert.true(routeOptions.suppressMarkers);
        assert.strictEqual(this.fitted.length, 1, 'and the map is fitted to what was drawn');
    });

    test('the plan viewer is handed working formatters', async function (assert) {
        this.storeResults.vehicle = [{ public_id: 'vehicle_1' }];
        this.postResponses['fleet-ops/orchestrator/run'] = {
            assignments: [{ order_id: 'order_1', vehicle_id: 'vehicle_1' }],
        };

        await render(hbs`<OrchestratorWorkbench />`);
        await click('button.btn-primary');

        assert.dom('[data-test-duration]').hasText('1h 2m', 'a duration over an hour carries both parts');
        assert.dom('[data-test-distance]').hasText('4.5 km', 'and a distance over a kilometre is scaled');
        assert.dom('[data-test-unix]').matchesText(/^\d{2}:\d{2}$/, 'a unix timestamp reads as a 24-hour clock');
        assert.dom('[data-test-unix-empty]').hasText('', 'and no timestamp reads as nothing');
        assert.dom('[data-test-stop-label]').hasText('A/D', 'stops are lettered from their position');
    });

    test('a run that places nothing surfaces why, and the error can be cleared', async function (assert) {
        this.postResponses['fleet-ops/orchestrator/run'] = { assignments: [], message: 'No vehicle had capacity' };

        await render(hbs`<OrchestratorWorkbench />`);
        await click('button.btn-primary');

        assert.dom('[data-test-plan-viewer]').doesNotExist('there is no plan to view');
        assert.dom('.driver-panel').containsText('No vehicle had capacity', 'the reason is shown in the right panel');
        assert.deepEqual(this.notified.at(-1), ['warning', 'No vehicle had capacity'], 'and raised once as a warning');

        await click('.driver-panel button:has(.fa-users)');
        assert.dom('[data-test-resource-panel]').exists('clearing the error returns to picking resources');
    });

    test('a run the server refuses is reported and leaves the workbench usable', async function (assert) {
        this.postResponses['fleet-ops/orchestrator/run'] = () => Promise.reject(new Error('the engine is unreachable'));

        await render(hbs`<OrchestratorWorkbench />`);
        await click('button.btn-primary');

        assert.deepEqual(this.notified.at(-1), ['serverError', 'the engine is unreachable']);
        assert.dom('[data-test-resource-panel]').exists('and the resource panel is still there to try again from');
    });

    test('committing sends the plan, reports it and reloads', async function (assert) {
        this.storeResults.vehicle = [{ public_id: 'vehicle_1' }];
        this.postResponses['fleet-ops/orchestrator/run'] = {
            assignments: [{ order_id: 'order_1', vehicle_id: 'vehicle_1' }],
        };
        this.postResponses['fleet-ops/orchestrator/commit'] = { committed: ['order_1'] };

        await render(hbs`<OrchestratorWorkbench />`);
        await click('button.btn-primary');

        const getsBefore = this.gets.length;
        await click('button.btn-success');

        const [path, payload] = this.posts.at(-1);
        assert.strictEqual(path, 'fleet-ops/orchestrator/commit');
        assert.deepEqual(payload.assignments, [{ order_id: 'order_1', vehicle_id: 'vehicle_1' }], 'the plan goes over as it stands');
        assert.strictEqual(this.notified.at(-1)[0], 'success', 'and the user is told');
        assert.dom('[data-test-plan-viewer]').doesNotExist('the plan is cleared once it is real');
        assert.dom('[data-test-resource-panel]').exists();
        assert.true(this.gets.length > getsBefore, 'and the data is reloaded behind it');
    });

    test('a commit the server refuses is reported and keeps the plan', async function (assert) {
        this.storeResults.vehicle = [{ public_id: 'vehicle_1' }];
        this.postResponses['fleet-ops/orchestrator/run'] = { assignments: [{ order_id: 'order_1', vehicle_id: 'vehicle_1' }] };
        this.postResponses['fleet-ops/orchestrator/commit'] = () => Promise.reject(new Error('commit rejected'));

        await render(hbs`<OrchestratorWorkbench />`);
        await click('button.btn-primary');
        await click('button.btn-success');

        assert.deepEqual(this.notified.at(-1), ['serverError', 'commit rejected']);
        assert.dom('[data-test-plan-viewer]').exists('the plan is kept so it can be committed again');
    });

    test('discarding a plan takes its routes off the map with it', async function (assert) {
        this.storeResults.vehicle = [{ public_id: 'vehicle_1' }];
        this.getResponses['fleet-ops/orchestrator/orders'] = {
            orders: [
                {
                    public_id: 'order_1',
                    payload: { pickup: { location: { coordinates: [103.8, 1.3] } }, dropoff: { location: { coordinates: [103.9, 1.4] } } },
                },
            ],
        };
        this.postResponses['fleet-ops/orchestrator/run'] = { assignments: [{ order_id: 'order_1', vehicle_id: 'vehicle_1' }] };

        await render(hbs`<OrchestratorWorkbench />`);
        await click('button.btn-primary');
        assert.strictEqual(this.routed.length, 1, 'the run drew a route');

        await click('button:has(.fa-xmark)');
        assert.strictEqual(this.removedRoutes.length, 1, 'discarding takes it off the map');
        assert.dom('[data-test-resource-panel]').exists('and returns to picking resources');
        assert.dom('[data-test-plan-viewer]').doesNotExist();
    });

    /** A DataTransfer stand-in: the browser gives a real one, a synthetic event needs this. */
    function dataTransfer(orderId) {
        const store = orderId ? { 'text/plain': orderId } : {};
        return {
            store,
            effectAllowed: null,
            dropEffect: null,
            setData(type, value) {
                store[type] = value;
            },
            getData(type) {
                return store[type] ?? '';
            },
        };
    }

    test('orders, vehicles and drivers are selected and cleared from their panels', async function (assert) {
        this.getResponses['fleet-ops/orchestrator/orders'] = { orders: [{ public_id: 'order_1' }, { public_id: 'order_2' }] };
        this.storeResults.vehicle = [{ public_id: 'vehicle_1' }];
        this.storeResults.driver = [{ public_id: 'driver_1' }];

        await render(hbs`<OrchestratorWorkbench />`);

        await click('[data-test-toggle-order]');
        assert.dom('[data-test-selected-orders]').hasText('1', 'the first order is selected');
        await click('[data-test-toggle-order]');
        assert.dom('[data-test-selected-orders]').hasText('0', 'and selecting it again lets it go');

        await click('[data-test-toggle-order]');
        await click('[data-test-clear-orders]');
        assert.dom('[data-test-selected-orders]').hasText('0', 'clearing takes the lot');

        await click('[data-test-toggle-vehicle]');
        assert.dom('[data-test-selected-vehicles]').hasText('1');
        await click('[data-test-toggle-vehicle]');
        assert.dom('[data-test-selected-vehicles]').hasText('0', 'vehicles toggle the same way');

        await click('[data-test-toggle-driver]');
        assert.dom('[data-test-selected-drivers]').hasText('1');
        await click('[data-test-toggle-driver]');
        assert.dom('[data-test-selected-drivers]').hasText('0', 'and so do drivers');

        await click('[data-test-toggle-vehicle]');
        await click('[data-test-clear-vehicles]');
        assert.dom('[data-test-selected-vehicles]').hasText('0');

        // Clearing drivers clears vehicles too: a driver selection implies its vehicle.
        await click('[data-test-toggle-vehicle]');
        await click('[data-test-toggle-driver]');
        await click('[data-test-clear-drivers]');
        assert.dom('[data-test-selected-drivers]').hasText('0');
        assert.dom('[data-test-selected-vehicles]').hasText('0', 'clearing drivers releases their vehicles with them');
    });

    test('a selection narrows what the run is asked to place', async function (assert) {
        this.getResponses['fleet-ops/orchestrator/orders'] = { orders: [{ public_id: 'order_1' }, { public_id: 'order_2' }] };
        this.storeResults.vehicle = [{ public_id: 'vehicle_1' }, { public_id: 'vehicle_2' }];
        this.storeResults.driver = [{ public_id: 'driver_1', vehicle_id: 'vehicle_2' }];

        await render(hbs`<OrchestratorWorkbench />`);
        await click('[data-test-toggle-order]');
        await click('[data-test-toggle-driver]');
        await click('button.btn-primary');

        const [, payload] = this.posts.at(-1);
        assert.deepEqual(payload.order_ids, ['order_1'], 'only the selected order is offered');
        assert.deepEqual(payload.vehicle_ids, ['vehicle_2'], "and the selected driver's own vehicle, resolved from vehicle_id");
        assert.deepEqual(payload.driver_ids, ['driver_1'], 'with the driver pinned');
    });

    test('dragging an unassigned order onto a vehicle assigns it', async function (assert) {
        this.getResponses['fleet-ops/orchestrator/orders'] = { orders: [{ public_id: 'order_1' }, { public_id: 'order_2' }] };
        this.storeResults.vehicle = [{ public_id: 'vehicle_1' }];
        this.postResponses['fleet-ops/orchestrator/run'] = { assignments: [{ order_id: 'order_2', vehicle_id: 'vehicle_1' }] };

        await render(hbs`<OrchestratorWorkbench />`);
        await click('button.btn-primary');
        assert.dom('[data-test-vehicle-count]').hasText('1');

        const dragged = dataTransfer();
        await triggerEvent('[data-test-order-drag]', 'dragstart', { dataTransfer: dragged });
        assert.strictEqual(dragged.getData('text/plain'), 'order_1', 'the drag carries the order id');
        assert.strictEqual(dragged.effectAllowed, 'move');

        await triggerEvent('[data-test-drop-target]', 'dragover', { dataTransfer: dragged });
        assert.strictEqual(dragged.dropEffect, 'move', 'the target says it will take it');

        await triggerEvent('[data-test-drop-target]', 'drop', { dataTransfer: dragged });
        await click('button.btn-success');
        const [, payload] = this.posts.at(-1);
        assert.deepEqual(
            payload.assignments.map((a) => [a.order_id, a.vehicle_id, a.sequence]),
            [
                ['order_2', 'vehicle_1', undefined],
                ['order_1', 'vehicle_1', 2],
            ],
            'the dropped order joins the plan behind the one already on that vehicle'
        );
    });

    test('dragging an order already in the plan moves it rather than adding it again', async function (assert) {
        this.getResponses['fleet-ops/orchestrator/orders'] = { orders: [{ public_id: 'order_1' }] };
        this.storeResults.vehicle = [{ public_id: 'vehicle_1' }, { public_id: 'vehicle_9' }];
        this.postResponses['fleet-ops/orchestrator/run'] = { assignments: [{ order_id: 'order_1', vehicle_id: 'vehicle_9', sequence: 1 }] };

        await render(hbs`<OrchestratorWorkbench />`);
        await click('button.btn-primary');

        await triggerEvent('[data-test-drop-target]', 'drop', { dataTransfer: dataTransfer('order_1') });
        await click('button.btn-success');

        const [, payload] = this.posts.at(-1);
        assert.deepEqual(
            payload.assignments,
            [{ order_id: 'order_1', vehicle_id: 'vehicle_1', driver_id: 'driver_1', sequence: 1, _overridden: true }],
            'the existing assignment is rewritten in place, keeping its sequence'
        );
    });

    test('a drop carrying nothing, or naming something unknown, changes no plan', async function (assert) {
        this.getResponses['fleet-ops/orchestrator/orders'] = { orders: [{ public_id: 'order_1' }] };
        this.storeResults.vehicle = [{ public_id: 'vehicle_1' }];
        this.postResponses['fleet-ops/orchestrator/run'] = { assignments: [{ order_id: 'order_1', vehicle_id: 'vehicle_1' }] };

        await render(hbs`<OrchestratorWorkbench />`);
        await click('button.btn-primary');

        await triggerEvent('[data-test-drop-target]', 'drop', { dataTransfer: dataTransfer() });
        await triggerEvent('[data-test-drop-target]', 'drop', { dataTransfer: dataTransfer('order_nobody_knows') });
        await click('button.btn-success');

        const [, payload] = this.posts.at(-1);
        assert.deepEqual(payload.assignments, [{ order_id: 'order_1', vehicle_id: 'vehicle_1' }], 'the plan is untouched by both');
    });

    test('the run message can be dismissed, and the import modal reloads the orders it brought in', async function (assert) {
        this.storeResults.vehicle = [{ public_id: 'vehicle_1' }];
        this.postResponses['fleet-ops/orchestrator/run'] = {
            assignments: [{ order_id: 'order_1', vehicle_id: 'vehicle_1' }],
            message: 'One placed',
        };

        await render(hbs`<OrchestratorWorkbench />`);
        await click('button.btn-primary');
        assert.dom('[data-test-run-message]').hasText('One placed');

        await click('[data-test-dismiss-message]');
        assert.dom('[data-test-run-message]').hasText('', 'dismissing takes it away');

        await click('button:has(.fa-xmark)');
        await click('[data-test-open-import]');
        const [name, options] = this.modals.at(-1);
        assert.strictEqual(name, 'modals/orchestrator-import');
        assert.true(options.hideAcceptButton, 'the modal drives its own completion');

        const getsBefore = this.gets.filter(([path]) => path.endsWith('/orders')).length;
        options.onImportComplete();
        await settled();
        assert.strictEqual(this.gets.filter(([path]) => path.endsWith('/orders')).length, getsBefore + 1, 'and finishing the import reloads the pool');
    });

    test('phases replace the legacy run, and run in sequence carrying their results forward', async function (assert) {
        this.getResponses['fleet-ops/orchestrator/orders'] = { orders: [{ public_id: 'order_1' }] };
        this.storeResults.vehicle = [{ public_id: 'vehicle_1' }];

        const runs = [
            { assignments: [{ order_id: 'order_1', vehicle_id: 'vehicle_1', sequence: 1 }] },
            { assignments: [{ order_id: 'order_1', vehicle_id: 'vehicle_1', driver_id: 'driver_7' }] },
        ];
        this.postResponses['fleet-ops/orchestrator/run'] = () => Promise.resolve(runs.shift());

        await render(hbs`<OrchestratorWorkbench />`);
        this.testBed.phases = [
            { id: 'p1', mode: 'assign_vehicles', engine: 'vroom' },
            { id: 'p2', mode: 'assign_drivers' },
        ];
        await click('button:has(.fa-list-ol)');
        await click('[data-test-run-phases]');

        const posted = this.posts.filter(([path]) => path.endsWith('/run'));
        assert.strictEqual(posted.length, 2, 'each phase is its own request');
        assert.strictEqual(posted[0][1].mode, 'assign_vehicles');
        assert.strictEqual(posted[0][1].options.engine, 'vroom', 'a phase engine overrides the default');
        assert.deepEqual(posted[0][1].prior_assignments, [], 'the first has nothing to build on');

        assert.strictEqual(posted[1][1].mode, 'assign_drivers');
        assert.deepEqual(posted[1][1].prior_assignments, [{ order_id: 'order_1', vehicle_id: 'vehicle_1', sequence: 1 }], 'the second is handed what the first decided');
        assert.strictEqual(posted[1][1].options.engine, 'greedy', 'and falls back to the built-in engine');

        assert.dom('[data-test-driver-phase]').hasText('yes', 'having run assign_drivers, the plan is shown by driver');
        assert.strictEqual(this.notified.filter(([kind]) => kind === 'warning').length, 0);
    });

    test('a phase marked autoCommit commits before the next one starts', async function (assert) {
        this.storeResults.vehicle = [{ public_id: 'vehicle_1' }];
        this.postResponses['fleet-ops/orchestrator/run'] = { assignments: [{ order_id: 'order_1', vehicle_id: 'vehicle_1' }] };

        await render(hbs`<OrchestratorWorkbench />`);
        this.testBed.phases = [
            { id: 'p1', mode: 'allocate', autoCommit: true },
            { id: 'p2', mode: 'optimize_routes' },
        ];
        await click('button:has(.fa-list-ol)');
        await click('[data-test-run-phases]');

        const order = this.posts.map(([path]) => path.split('/').at(-1));
        assert.deepEqual(order, ['run', 'commit', 'run'], 'the commit lands between the two runs');
    });

    test('a phase list edited without running is kept, and shows in the toolbar', async function (assert) {
        await render(hbs`<OrchestratorWorkbench />`);
        this.testBed.phases = [
            { id: 'p1', mode: 'allocate' },
            { id: 'p2', mode: 'optimize_routes' },
        ];

        await click('button:has(.fa-list-ol)');
        await click('[data-test-change-phases]');

        assert.dom('[data-test-phase-count]').hasText('2', 'the builder is handed back what it saved');
        assert.dom('.orchestrator-workbench').containsText('2', 'and the toolbar counts them');
        assert.strictEqual(this.posts.filter(([path]) => path.endsWith('/run')).length, 0, 'editing the list on its own runs nothing');
    });

    test('saving card fields closes the panel and reloads them', async function (assert) {
        await render(hbs`<OrchestratorWorkbench />`);
        const before = this.gets.filter(([path]) => path.endsWith('orchestrator-card-fields')).length;

        await click('button:has(.fa-table-columns)');
        await click('[data-test-card-fields-saved]');

        assert.dom('[data-test-card-fields]').doesNotExist('the panel closes itself');
        assert.strictEqual(this.gets.filter(([path]) => path.endsWith('orchestrator-card-fields')).length, before + 1, 'and the fields are read again');
    });

    test('the left panel collapses on its own toggle', async function (assert) {
        await render(hbs`<OrchestratorWorkbench />`);
        assert.dom('.order-pool-panel').doesNotHaveClass('w-0');

        await click('.workbench-panel-toggle.border-r');
        assert.dom('.order-pool-panel').hasClass('w-0', 'the left toggle collapses it');

        await click('.workbench-panel-toggle.border-r');
        assert.dom('.order-pool-panel').doesNotHaveClass('w-0');
    });

    test('an order already in the plan can be dragged out of it', async function (assert) {
        this.getResponses['fleet-ops/orchestrator/orders'] = { orders: [{ public_id: 'order_1' }] };
        this.storeResults.vehicle = [{ public_id: 'vehicle_1' }];
        this.postResponses['fleet-ops/orchestrator/run'] = { assignments: [{ order_id: 'order_1', vehicle_id: 'vehicle_1' }] };

        await render(hbs`<OrchestratorWorkbench />`);
        await click('button.btn-primary');

        const dragged = dataTransfer();
        await triggerEvent('[data-test-assigned-drag]', 'dragstart', { dataTransfer: dragged });

        assert.strictEqual(dragged.getData('text/plain'), 'order_1', 'the drag carries the assigned order');
        assert.strictEqual(dragged.effectAllowed, 'move');
    });

    test('both panels are resized by dragging their handles', async function (assert) {
        await render(hbs`<OrchestratorWorkbench />`);

        const leftHandle = document.querySelectorAll('.workbench-resize-handle')[0];
        const rightHandle = document.querySelectorAll('.workbench-resize-handle')[1];

        await triggerEvent(leftHandle, 'mousedown', { clientX: 300 });
        await triggerEvent(document, 'mousemove', { clientX: 360 });
        assert.dom('.order-pool-panel').hasAttribute('style', /width:350px/, 'dragging right widens the left panel by the delta');

        // Past the stops: the panel is held between 200 and 480.
        await triggerEvent(document, 'mousemove', { clientX: 1000 });
        assert.dom('.order-pool-panel').hasAttribute('style', /width:480px/, 'and stops at its maximum');
        await triggerEvent(document, 'mousemove', { clientX: 0 });
        assert.dom('.order-pool-panel').hasAttribute('style', /width:200px/, 'and at its minimum');

        await triggerEvent(document, 'mouseup');
        await triggerEvent(document, 'mousemove', { clientX: 400 });
        assert.dom('.order-pool-panel').hasAttribute('style', /width:200px/, 'letting go stops it following the pointer');
        assert.strictEqual(document.body.style.cursor, '', 'and puts the cursor back');

        // The right handle is inverted: dragging left makes the panel wider.
        await triggerEvent(rightHandle, 'mousedown', { clientX: 800 });
        await triggerEvent(document, 'mousemove', { clientX: 740 });
        assert.dom('.driver-panel').hasAttribute('style', /width:390px/, 'dragging left widens the right panel');

        await triggerEvent(document, 'mousemove', { clientX: 0 });
        assert.dom('.driver-panel').hasAttribute('style', /width:560px/, 'up to its own maximum');
        await triggerEvent(document, 'mousemove', { clientX: 2000 });
        assert.dom('.driver-panel').hasAttribute('style', /width:240px/, 'and down to its minimum');

        await triggerEvent(document, 'mouseup');
        assert.strictEqual(document.body.style.userSelect, '', 'and text is selectable again');
    });

    test('unassigned orders are pinned on the map, each stop with its own badge', async function (assert) {
        this.getResponses['fleet-ops/orchestrator/orders'] = {
            orders: [
                {
                    public_id: 'order_1',
                    tracking: 'TRK-1',
                    payload: { pickup: { location: { coordinates: [103.8, 1.3] }, address: 'Depot' }, dropoff: { location: { coordinates: [103.9, 1.4] }, address: 'Site' } },
                },
            ],
        };

        await render(hbs`<OrchestratorWorkbench />`);

        assert.dom('[data-test-tile]').hasAttribute('data-url', 'https://tiles.example/light/{z}/{x}/{y}.png', 'the tile layer takes the themed url');
        assert.dom('[data-test-marker]').exists({ count: 2 }, 'a pickup and a dropoff marker');

        const [pickup, dropoff] = [...document.querySelectorAll('[data-test-marker]')];
        assert.strictEqual(pickup.getAttribute('data-lat'), '1.3');
        assert.strictEqual(pickup.getAttribute('data-lng'), '103.8');
        assert.true(pickup.getAttribute('data-icon-html').includes('#22C55E'), 'the pickup badge is green');
        assert.true(pickup.getAttribute('data-icon-html').includes('>P<'), 'and lettered P');
        assert.true(dropoff.getAttribute('data-icon-html').includes('#EF4444'), 'the dropoff badge is red');

        assert.dom(pickup.querySelector('[data-test-marker-popup]')).containsText('TRK-1', 'the popup names the order by its tracking number');
        assert.dom(pickup.querySelector('[data-test-marker-popup]')).containsText('Depot');
        assert.dom(dropoff.querySelector('[data-test-marker-tooltip]')).containsText('Site');
    });

    test('a multi-drop order is pinned once per waypoint, numbered in order', async function (assert) {
        this.getResponses['fleet-ops/orchestrator/orders'] = {
            orders: [
                {
                    public_id: 'order_1',
                    payload: {
                        waypoints: [
                            { order: 2, place: { location: { coordinates: [103.9, 1.4] }, address: 'Second' } },
                            { order: 1, place: { location: { coordinates: [103.8, 1.3] }, address: 'First' } },
                            { order: 3, place: { location: { coordinates: [0, 0] }, address: 'Null island' } },
                        ],
                    },
                },
            ],
        };

        await render(hbs`<OrchestratorWorkbench />`);

        const markers = [...document.querySelectorAll('[data-test-marker]')];
        assert.strictEqual(markers.length, 2, 'the waypoint at 0,0 is not a place and is dropped');
        assert.strictEqual(markers[0].getAttribute('data-lat'), '1.3', 'the waypoints are pinned in their own order, not the given one');
        assert.true(markers[0].getAttribute('data-icon-html').includes('>1<'), 'and numbered from one');
        assert.true(markers[1].getAttribute('data-icon-html').includes('>2<'));
    });

    test('a place is read from any of the shapes the API returns it in', async function (assert) {
        this.getResponses['fleet-ops/orchestrator/orders'] = {
            orders: [
                { public_id: 'flat', payload: { pickup: { location: { lat: '1.1', lng: '103.1' } } } },
                { public_id: 'pair', payload: { pickup: { location: [1.2, 103.2] } } },
                { public_id: 'direct', payload: { pickup: { latitude: 1.3, longitude: 103.3 } } },
                { public_id: 'nowhere', payload: { pickup: { location: { coordinates: ['x', 'y'] } } } },
                { public_id: 'placeless', payload: { pickup: null } },
                { public_id: 'payloadless' },
            ],
        };

        await render(hbs`<OrchestratorWorkbench />`);

        assert.deepEqual(
            [...document.querySelectorAll('[data-test-marker]')].map((el) => el.getAttribute('data-lat')),
            ['1.1', '1.2', '1.3'],
            'a flat lat/lng, a numeric pair and direct latitude/longitude all read; nothing else does'
        );
    });

    test('a driver carrying a location is pinned with their status', async function (assert) {
        this.storeResults.driver = [
            { public_id: 'driver_1', name: 'Ada', location: { coordinates: [103.8, 1.3] }, online: true, vehicle_name: 'Truck 7', vehicle_avatar: '/assets/truck.png' },
            { public_id: 'driver_2', name: 'Bo', online: false },
        ];

        await render(hbs`<OrchestratorWorkbench />`);

        assert.dom('[data-test-marker]').exists({ count: 1 }, 'only the driver whose location is known is pinned');
        assert.dom('[data-test-marker-popup]').containsText('Ada');
        assert.dom('[data-test-marker-popup]').containsText('Truck 7');
        assert.dom('[data-test-marker-tooltip]').containsText('Ada');
    });

    test('the map is centred on the orders once they arrive, and a later geolocation does not override it', async function (assert) {
        this.latitude = 40;
        this.longitude = -74;
        let locate;
        this.userLocation = new Promise((resolve) => {
            locate = resolve;
        });
        this.getResponses['fleet-ops/orchestrator/orders'] = {
            orders: [{ public_id: 'order_1', payload: { pickup: { location: { coordinates: [103.8, 1.2] } }, dropoff: { location: { coordinates: [104.0, 1.4] } } } }],
        };

        await render(hbs`<OrchestratorWorkbench />`);

        assert.deepEqual(
            this.mapFits.at(-1),
            [
                [
                    [1.2, 103.8],
                    [1.4, 104],
                ],
                { padding: [40, 40], maxZoom: 14 },
            ],
            'several stops are fitted as a bounding box'
        );
        assert.deepEqual(this.mapViews, [[[40, -74], 11]], 'the only setView is the one the constructor made from the known coords');

        // The browser answers only now, with the orders already on screen.
        locate({ latitude: 51.5, longitude: -0.12 });
        await settled();
        assert.deepEqual(this.mapViews, [[[40, -74], 11]], 'and a geolocation arriving after them is ignored');
    });

    test('a single stop is zoomed to rather than fitted', async function (assert) {
        this.getResponses['fleet-ops/orchestrator/orders'] = {
            orders: [{ public_id: 'order_1', payload: { pickup: { location: { coordinates: [103.8, 1.3] } } } }],
        };

        await render(hbs`<OrchestratorWorkbench />`);

        assert.deepEqual(this.mapViews.at(-1), [[1.3, 103.8], 14], 'one point gets a sensible zoom instead of a box');
        assert.deepEqual(this.mapFits, [], 'and nothing is fitted');
    });

    test('with no orders, the browser location centres the map', async function (assert) {
        await render(hbs`<OrchestratorWorkbench />`);

        assert.deepEqual(this.mapViews.at(-1), [[51.5, -0.12], 11], 'the geolocation is applied once it resolves');
    });

    test('a browser that refuses to say where it is leaves the map alone', async function (assert) {
        this.userLocation = Promise.reject(new Error('permission denied'));

        await render(hbs`<OrchestratorWorkbench />`);

        assert.dom('[data-test-map]').exists('the refusal is swallowed and the workbench still comes up');
        assert.deepEqual(this.mapViews, [[[1.369, 103.8864], 11]], 'and the map keeps its default centre');
    });

    test('a plan pins a numbered stop per order and labels the time window', async function (assert) {
        this.getResponses['fleet-ops/orchestrator/orders'] = {
            orders: [
                {
                    public_id: 'order_1',
                    tracking: 'TRK-1',
                    time_window_start: '2026-06-22T09:00:00.000Z',
                    payload: { pickup: { location: { coordinates: [103.8, 1.3] }, address: 'Depot' }, dropoff: { location: { coordinates: [103.9, 1.4] }, address: 'Site' } },
                },
                { public_id: 'order_2', payload: { pickup: { location: { coordinates: [103.7, 1.2] }, address: 'Yard' } } },
            ],
        };
        this.storeResults.vehicle = [{ public_id: 'vehicle_1' }];
        this.storeResults.driver = [{ public_id: 'driver_1', vehicle_id: 'vehicle_1' }];
        this.postResponses['fleet-ops/orchestrator/run'] = {
            assignments: [
                { order_id: 'order_1', vehicle_id: 'vehicle_1', sequence: 1, arrival: 43200 },
                { order_id: 'order_2', vehicle_id: 'vehicle_1', sequence: 2 },
            ],
        };

        await render(hbs`<OrchestratorWorkbench />`);
        await click('button.btn-primary');

        const labels = [...document.querySelectorAll('[data-test-marker]')].map((el) => el.getAttribute('data-icon-html').match(/>([^<]+)</)[1]);
        assert.deepEqual(labels, ['A1', 'A2', 'B'], 'a two-stop order is lettered by stop, a one-stop order takes just its letter');
        assert.dom('[data-test-marker-tooltip]').containsText('TRK-1');
        assert.dom('[data-test-window]').matchesText(/^\d{2}:\d{2} – \d{2}:\d{2}$/, 'a start and an end read as a range');
    });

    test('a driver is matched to a group by their vehicle when the assignment names none', async function (assert) {
        this.getResponses['fleet-ops/orchestrator/orders'] = { orders: [{ public_id: 'order_1' }] };
        this.storeResults.vehicle = [{ public_id: 'vehicle_1' }];
        this.storeResults.driver = [{ public_id: 'driver_1', vehicle_id: 'vehicle_1' }];
        this.postResponses['fleet-ops/orchestrator/run'] = { assignments: [{ order_id: 'order_1', vehicle_id: 'vehicle_1' }] };

        await render(hbs`<OrchestratorWorkbench />`);
        await click('button.btn-primary');

        assert.dom('[data-test-vehicle-count]').hasText('1', 'the group is built even with no driver_id on the assignment');
        assert.dom('[data-test-duration]').hasText('1h 2m');
    });

    test('a response missing the key it was asked for is taken as empty', async function (assert) {
        this.getResponses['fleet-ops/orchestrator/orders'] = {};
        this.getResponses['fleet-ops/orchestrator/engines'] = {};

        await render(hbs`<OrchestratorWorkbench />`);

        assert.dom('[data-test-order-count]').hasText('0', 'no orders key means no orders');
        await click('button:has(.fa-list-ol)');
        assert.dom('[data-test-engine-count]').hasText('1', 'and no engines key falls back to the built-in one');
        assert.deepEqual(this.notified, [], 'neither is treated as an error');
    });

    test('a selected driver with no vehicle of their own pins none', async function (assert) {
        this.getResponses['fleet-ops/orchestrator/orders'] = { orders: [{ public_id: 'order_1' }] };
        this.storeResults.vehicle = [{ public_id: 'vehicle_1' }];
        this.storeResults.driver = [{ public_id: 'driver_1' }];

        await render(hbs`<OrchestratorWorkbench />`);
        await click('[data-test-toggle-driver]');
        await click('button.btn-primary');

        const [, payload] = this.posts.at(-1);
        assert.deepEqual(payload.vehicle_ids, ['vehicle_1'], 'with nothing resolved, every available vehicle is offered again');
        assert.deepEqual(payload.driver_ids, ['driver_1'], 'though the driver is still pinned');
    });

    test('a commit that reports no count is announced by what was sent', async function (assert) {
        this.storeResults.vehicle = [{ public_id: 'vehicle_1' }];
        this.postResponses['fleet-ops/orchestrator/run'] = { assignments: [{ order_id: 'order_1', vehicle_id: 'vehicle_1' }] };
        this.postResponses['fleet-ops/orchestrator/commit'] = {};

        await render(hbs`<OrchestratorWorkbench />`);
        await click('button.btn-primary');
        await click('button.btn-success');

        assert.strictEqual(this.notified.at(-1)[0], 'success', 'the commit is still reported');
    });

    test('a route the engine could not draw is skipped without taking the others down', async function (assert) {
        this.getResponses['fleet-ops/orchestrator/orders'] = {
            orders: [
                { public_id: 'order_1', payload: { pickup: { location: { coordinates: [103.8, 1.3] } }, dropoff: { location: { coordinates: [103.9, 1.4] } } } },
                { public_id: 'order_2', payload: { pickup: { location: { coordinates: [103.7, 1.2] } }, dropoff: { location: { coordinates: [103.6, 1.1] } } } },
            ],
        };
        this.storeResults.vehicle = [{ public_id: 'vehicle_1' }, { public_id: 'vehicle_2' }];
        this.postResponses['fleet-ops/orchestrator/run'] = {
            assignments: [
                { order_id: 'order_1', vehicle_id: 'vehicle_1' },
                { order_id: 'order_2', vehicle_id: 'vehicle_2' },
            ],
        };
        // The first vehicle's route comes back with no geometry; the second is fine.
        let call = 0;
        this.routeHandleFor = (waypoints) => (call++ === 0 ? { route: { coordinates: [] } } : { route: { coordinates: waypoints } });

        await render(hbs`<OrchestratorWorkbench />`);
        await click('button.btn-primary');

        assert.strictEqual(this.routed.length, 2, 'both were attempted');
        assert.strictEqual(this.fitted.length, 1, 'and the map is fitted to the one that came back');
        assert.deepEqual(this.fitted[0][0], [
            [1.2, 103.7],
            [1.1, 103.6],
        ]);
    });

    test('a plan naming a vehicle nobody has is still drawn, tagged as unknown', async function (assert) {
        this.getResponses['fleet-ops/orchestrator/orders'] = {
            orders: [{ public_id: 'order_1', payload: { pickup: { location: { coordinates: [103.8, 1.3] } }, dropoff: { location: { coordinates: [103.9, 1.4] } } } }],
        };
        this.storeResults.vehicle = [];
        this.postResponses['fleet-ops/orchestrator/run'] = { assignments: [{ order_id: 'order_1', vehicle_id: 'vehicle_ghost' }] };

        await render(hbs`<OrchestratorWorkbench />`);
        await click('button.btn-primary');

        assert.strictEqual(this.routed.at(-1)[1].tag, 'orchestrator:unknown', 'the tag falls all the way through');
        assert.dom('[data-test-duration]').hasText('1h 2m', 'and the group still reaches the plan viewer');
    });

    test('a stop with no summary and a waypoint that is its own place are both read', async function (assert) {
        this.getResponses['fleet-ops/orchestrator/orders'] = {
            orders: [
                {
                    public_id: 'order_1',
                    payload: {
                        waypoints: [
                            { order: 1, location: { coordinates: [103.8, 1.3] } },
                            { order: 2, place: { location: { coordinates: [103.9, 1.4] } } },
                        ],
                    },
                },
            ],
        };
        this.storeResults.vehicle = [{ public_id: 'vehicle_1' }];
        this.postResponses['fleet-ops/orchestrator/run'] = { assignments: [{ order_id: 'order_1', vehicle_id: 'vehicle_1' }] };

        await render(hbs`<OrchestratorWorkbench />`);

        assert.dom('[data-test-marker]').exists({ count: 2 }, 'a waypoint that is itself the place is read the same as one wrapping it');
        assert.dom('[data-test-marker-tooltip]').hasText('order_1 —', 'a place with no address contributes none, leaving the order id alone');

        await click('button.btn-primary');
        assert.dom('[data-test-summary-duration]').hasText('', 'a group the run reported no timings for has an empty summary');
    });

    test('a map that mounts after the orders re-centres on them', async function (assert) {
        this.testBed.deferMapLoad = true;
        this.getResponses['fleet-ops/orchestrator/orders'] = {
            orders: [{ public_id: 'order_1', payload: { pickup: { location: { coordinates: [103.8, 1.2] } }, dropoff: { location: { coordinates: [104.0, 1.4] } } } }],
        };

        await render(hbs`<OrchestratorWorkbench />`);
        assert.deepEqual(this.mapFits, [], 'nothing is fitted while the map is still coming up');

        this.testBed.loadMap();
        await settled();

        assert.deepEqual(
            this.mapFits.at(-1),
            [
                [
                    [1.2, 103.8],
                    [1.4, 104],
                ],
                { padding: [40, 40], maxZoom: 14 },
            ],
            'the map is fitted to the orders that were already loaded, not to the default centre'
        );
        assert.deepEqual(this.mapViews, [], 'and no setView is made from the default');
    });

    test('the formatters answer sensibly at their edges', async function (assert) {
        this.storeResults.vehicle = [{ public_id: 'vehicle_1' }];
        this.postResponses['fleet-ops/orchestrator/run'] = { assignments: [{ order_id: 'order_1', vehicle_id: 'vehicle_1' }] };

        await render(hbs`<OrchestratorWorkbench />`);
        await click('button.btn-primary');

        assert.dom('[data-test-short-duration]').hasText('5m', 'under an hour is minutes alone');
        assert.dom('[data-test-no-duration]').hasText('', 'and no duration is nothing at all');
        assert.dom('[data-test-short-distance]').hasText('400 m', 'under a kilometre stays in metres');
        assert.dom('[data-test-no-distance]').hasText('');
        assert.dom('[data-test-window-open]').matchesText(/^\d{2}:\d{2}$/, 'a window with only a start reads as that time');
        assert.dom('[data-test-window-instant]').matchesText(/^\d{2}:\d{2}$/, 'and one that starts and ends together reads once');
        assert.dom('[data-test-window-none]').hasText('', 'no window reads as nothing');
        // A date the browser cannot parse renders the words "Invalid Date" — the guard that was
        // meant to catch this cannot fire. See DEFECTS #92.
        assert.dom('[data-test-window-unreadable]').hasText('Invalid Date', 'an unparseable date is shown as-is rather than blanked');
    });

    test('a run that reports no assignments key at all is taken as none', async function (assert) {
        this.postResponses['fleet-ops/orchestrator/run'] = { message: 'nothing to do' };

        await render(hbs`<OrchestratorWorkbench />`);
        await click('button.btn-primary');

        assert.dom('[data-test-plan-viewer]').doesNotExist('there is no plan');
        assert.deepEqual(this.notified.at(-1), ['warning', 'nothing to do'], 'and it is raised as a warning');
    });

    test('dropping onto a plan of several leaves the others where they were', async function (assert) {
        this.getResponses['fleet-ops/orchestrator/orders'] = { orders: [{ public_id: 'order_1' }, { public_id: 'order_2' }] };
        this.storeResults.vehicle = [{ public_id: 'vehicle_1' }, { public_id: 'vehicle_9' }];
        this.postResponses['fleet-ops/orchestrator/run'] = {
            assignments: [
                { order_id: 'order_1', vehicle_id: 'vehicle_9', sequence: 1 },
                { order_id: 'order_2', vehicle_id: 'vehicle_9', sequence: 2 },
            ],
        };

        await render(hbs`<OrchestratorWorkbench />`);
        await click('button.btn-primary');

        await triggerEvent('[data-test-drop-target]', 'drop', { dataTransfer: dataTransfer('order_1') });
        await click('button.btn-success');

        const [, payload] = this.posts.at(-1);
        assert.deepEqual(
            payload.assignments.map((a) => [a.order_id, a.vehicle_id]),
            [
                ['order_1', 'vehicle_1'],
                ['order_2', 'vehicle_9'],
            ],
            'only the dropped order moves'
        );
    });

    test('the tile layer follows the dark theme', async function (assert) {
        document.documentElement.classList.add('dark');

        try {
            await render(hbs`<OrchestratorWorkbench />`);
            assert.dom('[data-test-tile]').hasAttribute('data-url', 'https://tiles.example/dark/{z}/{x}/{y}.png');
        } finally {
            document.documentElement.classList.remove('dark');
        }
    });

    test('a stop on the equator is pinned but cannot anchor a route leg', async function (assert) {
        this.getResponses['fleet-ops/orchestrator/orders'] = {
            orders: [
                {
                    public_id: 'order_1',
                    // lat 0 is a real place; `_placeCoords` accepts it because the pair is not 0,0.
                    payload: { pickup: { location: { coordinates: [103.8, 0] } }, dropoff: { location: { coordinates: [103.9, 1.4] } } },
                },
            ],
        };
        this.storeResults.vehicle = [{ public_id: 'vehicle_1' }];
        this.postResponses['fleet-ops/orchestrator/run'] = { assignments: [{ order_id: 'order_1', vehicle_id: 'vehicle_1' }] };

        await render(hbs`<OrchestratorWorkbench />`);
        assert.dom('[data-test-marker]').exists({ count: 2 }, 'both stops are pinned');

        await click('button.btn-primary');
        assert.deepEqual(this.routed, [], 'but one usable point is not enough to ask for a route');
    });

    test('null island is not a place, in any of the shapes it can arrive in', async function (assert) {
        this.getResponses['fleet-ops/orchestrator/orders'] = {
            orders: [
                { public_id: 'geojson', payload: { pickup: { location: { coordinates: [0, 0] } } } },
                { public_id: 'flat', payload: { pickup: { location: { lat: 0, lng: 0 } } } },
                { public_id: 'pair', payload: { pickup: { location: [0, 0] } } },
                { public_id: 'direct', payload: { pickup: { latitude: 0, longitude: 0 } } },
                { public_id: 'real', payload: { pickup: { location: { coordinates: [103.8, 1.3] } } } },
            ],
        };

        await render(hbs`<OrchestratorWorkbench />`);

        assert.dom('[data-test-marker]').exists({ count: 1 }, 'only the one real place is pinned');
        assert.dom('[data-test-marker]').hasAttribute('data-lat', '1.3');
    });

    test('waypoints and stops with no order of their own keep the order they came in', async function (assert) {
        this.getResponses['fleet-ops/orchestrator/orders'] = {
            orders: [
                {
                    public_id: 'order_1',
                    payload: {
                        waypoints: [{ place: { location: { coordinates: [103.8, 1.3] }, address: 'First' } }, { place: { location: { coordinates: [103.9, 1.4] }, address: 'Second' } }],
                    },
                },
                { public_id: 'order_2', payload: { pickup: { location: { coordinates: [103.7, 1.2] } } } },
            ],
        };
        this.storeResults.vehicle = [{ public_id: 'vehicle_1' }];
        this.postResponses['fleet-ops/orchestrator/run'] = {
            assignments: [
                { order_id: 'order_1', vehicle_id: 'vehicle_1' },
                { order_id: 'order_2', vehicle_id: 'vehicle_1' },
            ],
        };

        await render(hbs`<OrchestratorWorkbench />`);
        assert.deepEqual(
            [...document.querySelectorAll('[data-test-marker]')].map((el) => el.getAttribute('data-lat')),
            ['1.3', '1.4', '1.2'],
            'unnumbered waypoints stay in the order the payload listed them'
        );

        await click('button.btn-primary');
        assert.deepEqual(
            this.routed.at(-1)[0],
            [
                [1.3, 103.8],
                [1.4, 103.9],
                [1.2, 103.7],
            ],
            'and unsequenced assignments are threaded in the order they were returned'
        );
    });

    test('a card-fields read that fails before it returns a promise is survived', async function (assert) {
        // The `.catch(() => null)` chained on the request only covers a rejected promise; a
        // service that raises before returning one lands in the surrounding try/catch instead.
        this.getResponses['fleet-ops/settings/orchestrator-card-fields'] = () => {
            throw new Error('no fetch adapter');
        };

        await render(hbs`<OrchestratorWorkbench />`);

        assert.dom('[data-test-order-pool]').exists('the workbench still comes up');
        assert.deepEqual(this.notified, [], 'and the card fields failing is not worth telling anyone about');
    });
});
