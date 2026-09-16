import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import Service from '@ember/service';
import EmberObject from '@ember/object';
import DetailsController from '@fleetbase/fleetops-engine/controllers/connectivity/fuel-providers/details';
import Resolver from 'ember-resolver';
import SyncRoute from '@fleetbase/fleetops-engine/routes/connectivity/fuel-providers/details/sync';
import MatchingController from '@fleetbase/fleetops-engine/controllers/connectivity/fuel-providers/details/matching';
import SettingsRoute from '@fleetbase/fleetops-engine/routes/connectivity/fuel-providers/details/settings';
import DetailsRoute from '@fleetbase/fleetops-engine/routes/connectivity/fuel-providers/details';
import EditRoute from '@fleetbase/fleetops-engine/routes/connectivity/fuel-providers/edit';
import TransactionRoute from '@fleetbase/fleetops-engine/routes/management/fuel-transactions/index/details';

module('Unit | fuel integration | details', function (hooks) {
    // These route/controller tests do not need the host app's widget initializers.
    setupTest(hooks, { resolver: Resolver.create({ namespace: { modulePrefix: 'dummy' } }) });
    hooks.beforeEach(function () {
        this.owner.register('service:host-router', class extends Service {});
        this.owner.register('service:notifications', class extends Service {});
        this.owner.register('service:modals-manager', class extends Service {});
    });

    test('sync uses the parent connection without reloading the router or querying unscoped history', function (assert) {
        const connection = { id: 'connection-123' };
        this.owner.register('route:fuel-sync-test', SyncRoute);
        const route = this.owner.lookup('route:fuel-sync-test');
        route.modelFor = () => connection;
        assert.strictEqual(route.model(), connection);
    });

    test('matching review opens the current connection with unmatched filters', function (assert) {
        assert.expect(2);
        this.owner.register(
            'service:host-router',
            class extends Service {
                transitionTo(route, options) {
                    assert.strictEqual(route, 'console.fleet-ops.management.fuel-transactions.index');
                    assert.deepEqual(options.queryParams, { sync_status: 'unmatched', connection: 'connection-123', page: 1 });
                }
            }
        );
        this.owner.register('controller:fuel-matching-test', MatchingController);
        const controller = this.owner.lookup('controller:fuel-matching-test');
        controller.set('model', { id: 'connection-123' });
        controller.reviewUnmatchedTransactions();
    });

    test('legacy settings links redirect directly to editing the connection', function (assert) {
        assert.expect(2);
        const connection = { uuid: 'connection-123' };
        this.owner.register(
            'service:host-router',
            class extends Service {
                replaceWith(route, model) {
                    assert.strictEqual(route, 'console.fleet-ops.connectivity.fuel-providers.edit');
                    assert.strictEqual(model, connection);
                }
            }
        );
        this.owner.register('route:fuel-settings-test', SettingsRoute);
        const route = this.owner.lookup('route:fuel-settings-test');
        route.modelFor = () => connection;
        route.redirect();
    });

    test('activity refresh updates the existing record without changing its identity or refreshing the router', async function (assert) {
        this.owner.register(
            'service:fetch',
            class extends Service {
                async get(path) {
                    assert.strictEqual(path, 'fuel-provider-connections/connection-123/activity');
                    return { connection: { status: 'active', last_synced_at: '2026-09-15T12:00:00Z' }, totals: { transactions: 1 }, runs: [] };
                }
            }
        );
        this.owner.register('controller:fuel-details-test', DetailsController);
        const controller = this.owner.lookup('controller:fuel-details-test');
        const connection = EmberObject.create({ id: 'connection-123', status: 'connected' });
        controller.set('model', connection);
        await controller.refreshActivity();
        assert.strictEqual(controller.model, connection);
        assert.strictEqual(connection.id, 'connection-123');
        assert.strictEqual(connection.status, 'active');
        assert.strictEqual(controller.totals.transactions, 1);
    });

    test('activity request errors preserve the current page and previous result', async function (assert) {
        this.owner.register(
            'service:fetch',
            class extends Service {
                async get() {
                    throw new Error('Unavailable');
                }
            }
        );
        this.owner.register('controller:fuel-details-test', DetailsController);
        const controller = this.owner.lookup('controller:fuel-details-test');
        controller.set('model', EmberObject.create({ id: 'connection-123' }));
        controller.activity = { totals: { transactions: 1 } };
        await controller.refreshActivity();
        assert.strictEqual(controller.totals.transactions, 1);
        assert.ok(controller.activityError.includes('try Refresh'));
    });

    test('public URL routes query by public ID without reserving it as the store primary key', async function (assert) {
        const calls = [];
        this.owner.register(
            'service:store',
            class extends Service {
                queryRecord(type, params) {
                    calls.push([type, params]);
                    return Promise.resolve({ id: 'uuid' });
                }
                findRecord() {
                    throw new Error('Public IDs must not be passed to findRecord');
                }
            }
        );
        for (const [name, RouteClass, type] of [
            ['details', DetailsRoute, 'fuel-provider-connection'],
            ['edit', EditRoute, 'fuel-provider-connection'],
            ['transaction', TransactionRoute, 'fuel-provider-transaction'],
        ]) {
            this.owner.register(`route:fuel-${name}-test`, RouteClass);
            const route = this.owner.lookup(`route:fuel-${name}-test`);
            await route.model({ public_id: 'public-id' });
            await route.model({ public_id: 'public-id' });
            assert.deepEqual(calls.slice(-2), [
                [type, { public_id: 'public-id', single: true }],
                [type, { public_id: 'public-id', single: true }],
            ]);
        }
    });

    test('Test opens a diagnostics dialog without sending a request or refreshing the router', function (assert) {
        assert.expect(3);
        this.owner.register(
            'service:fetch',
            class extends Service {
                post() {
                    throw new Error('Dialog must wait for Run Test');
                }
            }
        );
        this.owner.register(
            'service:modals-manager',
            class extends Service {
                show(component, options) {
                    assert.strictEqual(component, 'modals/fuel-connection-diagnostics');
                    assert.strictEqual(options.connection.id, 'connection-123');
                    assert.strictEqual(options.acceptButtonText, 'Run Test');
                }
            }
        );
        this.owner.register('controller:fuel-details-test', DetailsController);
        const controller = this.owner.lookup('controller:fuel-details-test');
        controller.set('model', EmberObject.create({ id: 'connection-123' }));
        controller.openConnectionTestDialog();
    });
});
