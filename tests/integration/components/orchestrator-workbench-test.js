import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';
import registerTemplateOnly from 'dummy/tests/helpers/register-template-only';

/**
 * The workbench owns cross-cutting state and delegates every panel to a sub-component, so the
 * honest unit here is the workbench itself with those panels stubbed. The map is stubbed as a
 * non-yielding element: `ember-leaflet` is not installed in this package (DEFECTS #75), so
 * `<LeafletMap>` cannot resolve at all without a stand-in.
 */
function stubPanels(owner) {
    registerTemplateOnly(owner, 'leaflet-map', hbs`<div data-test-map></div>`);
    registerTemplateOnly(owner, 'orchestrator/phase-builder', hbs`<div data-test-phase-builder></div>`);
    registerTemplateOnly(owner, 'orchestrator/card-fields-settings', hbs`<div data-test-card-fields></div>`);
    registerTemplateOnly(owner, 'orchestrator/order-pool', hbs`<div data-test-order-pool>{{@orders.length}}</div>`);
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
            <span data-test-duration>{{@formatDuration 3725}}</span>
            <span data-test-distance>{{@formatDistance 4520}}</span>
            <span data-test-unix>{{@formatUnixTime 43200}}</span>
            <span data-test-unix-empty>{{@formatUnixTime 0}}</span>
            <span data-test-stop-label>{{@getStopLabel 0}}/{{@getStopLabel 3}}</span>
        </div>`
    );
    registerTemplateOnly(owner, 'orchestrator/resource-panel', hbs`<div data-test-resource-panel>{{@vehicles.length}}/{{@drivers.length}}</div>`);
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

        assert.dom('[data-test-order-pool]').hasText('2', 'the pool is handed the orders');
        assert.dom('[data-test-resource-panel]').hasText('1/2', 'and the resource panel its vehicles and drivers');
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
});
