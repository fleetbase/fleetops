import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import Service from '@ember/service';
import { setOwner } from '@ember/application';
import OrchestratorWorkbenchComponent from 'dummy/components/orchestrator-workbench';

module('Unit | Component | orchestrator-workbench', function (hooks) {
    setupTest(hooks);

    hooks.beforeEach(function () {
        const context = this;
        this.responses = {
            'fleet-ops/orchestrator/orders': { orders: [] },
            'fleet-ops/orchestrator/engines': {
                engines: [
                    { id: 'greedy', name: 'Greedy (built-in)' },
                    { id: 'vroom', name: 'VROOM' },
                ],
            },
            'fleet-ops/settings/orchestrator-settings': { allocation_engine: 'vroom' },
            'fleet-ops/settings/orchestrator-card-fields': { settings: null },
        };
        this.posts = [];
        this.runResult = { assignments: [], unassigned: [] };
        this.warnings = [];

        this.owner.register(
            'service:fetch',
            class extends Service {
                get(url) {
                    const response = context.responses[url];
                    return response instanceof Error ? Promise.reject(response) : Promise.resolve(response);
                }

                post(url, payload) {
                    context.posts.push({ url, payload });
                    return Promise.resolve(context.runResult);
                }
            }
        );
        this.owner.register(
            'service:store',
            class extends Service {
                query() {
                    return Promise.resolve({ toArray: () => [] });
                }
            }
        );
        this.owner.register(
            'service:location',
            class extends Service {
                getLatitude() {
                    return null;
                }

                getLongitude() {
                    return null;
                }

                getUserLocation() {
                    return new Promise(() => {});
                }
            }
        );
        this.owner.register(
            'service:notifications',
            class extends Service {
                warning(message) {
                    context.warnings.push(message);
                }

                serverError(error) {
                    throw error;
                }
            }
        );
        this.owner.register(
            'service:intl',
            class extends Service {
                t(key) {
                    return key;
                }
            }
        );

        this.createWorkbench = async () => {
            // Built without the constructor (which needs the component manager
            // and boots the map); the services are the stubs registered above.
            const lookup = (name) => ({ value: this.owner.lookup(`service:${name}`) });
            const workbench = Object.create(OrchestratorWorkbenchComponent.prototype, {
                args: { value: {} },
                fetch: lookup('fetch'),
                notifications: lookup('notifications'),
                intl: lookup('intl'),
                _drawRoutingControls: { value: () => {} },
            });
            setOwner(workbench, this.owner);
            await workbench.loadEngines.perform();
            return workbench;
        };
    });

    test('it starts new phases with the engine selected in orchestrator settings', async function (assert) {
        const workbench = await this.createWorkbench();

        assert.strictEqual(workbench.defaultEngine, 'vroom');
        assert.deepEqual(
            workbench.availableEngines.map((engine) => engine.id),
            ['greedy', 'vroom']
        );
    });

    test('it leaves the default engine unset when the settings cannot be loaded', async function (assert) {
        this.responses['fleet-ops/settings/orchestrator-settings'] = new Error('forbidden');

        const workbench = await this.createWorkbench();

        assert.strictEqual(workbench.defaultEngine, null);
    });

    test('a phase without an engine lets the server use the configured engine', async function (assert) {
        const workbench = await this.createWorkbench();

        assert.strictEqual(workbench._legacyPhase().engine, null);

        await workbench._runSinglePhase.perform({ mode: 'allocate' });

        assert.strictEqual(this.posts[0].url, 'fleet-ops/orchestrator/run');
        assert.strictEqual(this.posts[0].payload.options.engine, null);
    });

    test('it surfaces the fallback warning and the route totals the server reports', async function (assert) {
        this.runResult = {
            assignments: [{ order_id: 'order_one', vehicle_id: 'vehicle_one', sequence: 1, arrival: 1778918400, route_distance: 4200, route_duration: 900 }],
            unassigned: [],
            summary: { engine: 'greedy', requested_engine: 'vroom', fallback_reason: 'VROOM returned an error: HTTP 401' },
            warning: 'The "greedy" engine was used instead of "vroom": VROOM returned an error: HTTP 401',
        };
        const workbench = await this.createWorkbench();

        await workbench._runSinglePhase.perform({ mode: 'assign_vehicles', engine: 'vroom' });

        assert.strictEqual(this.posts[0].payload.options.engine, 'vroom');
        assert.deepEqual(this.warnings, [this.runResult.warning]);
        assert.strictEqual(workbench.orchestratorRunMessage, this.runResult.warning);
        assert.deepEqual(workbench.routeSummaries, { vehicle_one: { duration: 900, distance: 4200 } });
    });
});
