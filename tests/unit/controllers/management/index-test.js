import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import { setupIntl } from 'ember-intl/test-support';
import Service from '@ember/service';

class FetchStubService extends Service {
    calls = [];
    itemsResponse = { items: [], groups: [], counts: {}, summary: {}, meta: { total: 0, page: 1, pages: 1, limit: 50 }, snooze_schedule: [], sources: {} };
    summaryResponse = { summary: { open: 3, snoozed: 1 }, counts: { overdue: 2 } };
    postResponse = { state: { status: 'acknowledged' } };
    failNextPost = false;

    briefingResponse = {
        score: { value: 90, delta: null },
        categories: [],
        brief: [],
        decisions: [{ key: 'd1', title: 'Do it', keys: ['issue_open:a'], confirm: { endpoint: 'x' } }],
        yesterday: {},
    };
    agendaResponse = { window: { key: '24h' }, lanes: {}, overdue: [], later: [], anytime: [], handovers: [], counts: {} };
    handoverResponse = {
        handover: {
            key: 'shift_handover:d',
            driver: { label: 'Luis' },
            shift: { uuid: 'sh1' },
            orders: [{ uuid: 'o1' }, { uuid: 'o2' }],
            suggested: { driver: { uuid: 'driver-alves', label: 'Tomas' } },
        },
    };

    async get(url, params) {
        this.calls.push(['get', url, params]);
        if (url.startsWith('fleet-ops/radar/summary')) {
            return this.summaryResponse;
        }
        if (url.startsWith('fleet-ops/radar/briefing')) {
            return this.briefingResponse;
        }
        if (url.startsWith('fleet-ops/radar/agenda')) {
            return this.agendaResponse;
        }
        if (url.startsWith('fleet-ops/radar/handovers')) {
            return this.handoverResponse;
        }
        return this.itemsResponse;
    }

    async patch(url, body) {
        this.calls.push(['patch', url, body]);
        return {};
    }

    async post(url, body) {
        this.calls.push(['post', url, body]);
        if (this.failNextPost) {
            this.failNextPost = false;
            throw new Error('nope');
        }
        return this.postResponse;
    }
}

class AppCacheStubService extends Service {
    store = {};
    get(key, fallback) {
        return key in this.store ? this.store[key] : fallback;
    }
    set(key, value) {
        this.store[key] = value;
    }
}

class NotificationsStubService extends Service {
    messages = [];
    success(message) {
        this.messages.push(['success', message]);
    }
    info(message) {
        this.messages.push(['info', message]);
    }
    warning(message) {
        this.messages.push(['warning', message]);
    }
    serverError(err) {
        this.messages.push(['error', err?.message]);
    }
}

class HostRouterStubService extends Service {
    transitions = [];
    transitionTo(...args) {
        this.transitions.push(args);
    }
}

function item(key, extra = {}) {
    const [rule] = key.split(':');
    return { key, rule, category: 'issues', severity: 'warning', title: key, due_bucket: 'none', state: { status: 'open' }, actions: ['acknowledge', 'snooze', 'assign'], ...extra };
}

module('Unit | Controller | management/index (radar)', function (hooks) {
    setupTest(hooks);
    setupIntl(hooks, 'en-us');

    hooks.beforeEach(function () {
        this.owner.register('service:fetch', FetchStubService);
        this.owner.register('service:app-cache', AppCacheStubService);
        this.owner.register('service:notifications', NotificationsStubService);
        this.owner.register('service:host-router', HostRouterStubService);
        this.fetch = this.owner.lookup('service:fetch');
        this.appCache = this.owner.lookup('service:app-cache');
        this.notifications = this.owner.lookup('service:notifications');
        this.controller = this.owner.lookup('controller:management/index');
    });

    test('it reads the view mode and saved views from the app cache', function (assert) {
        this.appCache.set('fleetops:radar:view', 'agenda');
        this.appCache.set('fleetops:radar:views', [{ id: 'view-1', label: 'Mine', filters: 'issues' }, { broken: true }]);

        const controller = this.owner.factoryFor('controller:management/index').create();

        assert.strictEqual(controller.view, 'agenda');
        assert.deepEqual(
            controller.allViews.map((view) => view.id),
            ['my-assignments', 'shift-changes', 'due-this-week', 'unmatched-fuel', 'view-1'],
            'defaults first, then the saved ones, dropping malformed entries'
        );
    });

    test('pills carry counts from the payload and toggle into the filters query param', async function (assert) {
        this.fetch.itemsResponse = { ...this.fetch.itemsResponse, counts: { overdue: 3, issues: 8 } };
        await this.controller.loadItems.perform();

        const pills = this.controller.pills;
        assert.strictEqual(pills.find((pill) => pill.key === 'overdue').count, 3);
        assert.strictEqual(pills.find((pill) => pill.key === 'issues').count, 8);
        assert.strictEqual(pills.find((pill) => pill.key === 'fuel').count, 0);

        this.controller.togglePill('overdue');
        this.controller.togglePill('issues');
        assert.strictEqual(this.controller.filters, 'overdue,issues');
        assert.true(this.controller.isFiltered);

        this.controller.togglePill('overdue');
        assert.strictEqual(this.controller.filters, 'issues');

        await this.controller.loadItems.last;
        const [, url, params] = this.fetch.calls.at(-1);
        assert.strictEqual(url, 'fleet-ops/radar/items');
        assert.strictEqual(params.filters, 'issues');
        assert.strictEqual(params.status, 'open');

        this.controller.clearFilters();
        assert.strictEqual(this.controller.filters, '');
        assert.false(this.controller.isFiltered);
    });

    test('applying a saved view sets status, filters, query and assignee; saving and deleting persist to the cache', function (assert) {
        this.controller.applyView({ id: 'my-assignments', status: 'open', filters: '', assigned: 'me', q: '' });
        assert.strictEqual(this.controller.assigned, 'me');
        assert.strictEqual(this.controller.saved, 'my-assignments');
        assert.true(this.controller.isFiltered, 'my assignments narrows the list');

        this.controller.savedViews = [{ id: 'view-9', label: 'Nine', filters: 'fuel' }];
        this.controller.writeSavedViews();
        assert.deepEqual(this.appCache.get('fleetops:radar:views'), [{ id: 'view-9', label: 'Nine', filters: 'fuel' }]);

        this.controller.saved = 'view-9';
        this.controller.deleteView({ id: 'view-9' });
        assert.deepEqual(this.controller.savedViews, []);
        assert.strictEqual(this.controller.saved, '', 'deleting the active view clears it');
        assert.deepEqual(this.appCache.get('fleetops:radar:views'), []);
    });

    test('selection and focus move over the loaded items', async function (assert) {
        this.fetch.itemsResponse = { ...this.fetch.itemsResponse, items: [item('issue_open:a'), item('issue_open:b'), item('issue_open:c')] };
        await this.controller.loadItems.perform();

        this.controller.focusNext();
        assert.strictEqual(this.controller.focusedKey, 'issue_open:a');
        this.controller.focusNext();
        this.controller.focusNext();
        this.controller.focusNext();
        assert.strictEqual(this.controller.focusedKey, 'issue_open:c', 'focus stops at the last item');
        this.controller.focusPrevious();
        assert.strictEqual(this.controller.focusedKey, 'issue_open:b');

        this.controller.toggleFocusedSelection();
        assert.deepEqual(this.controller.selection, ['issue_open:b']);
        this.controller.selectAll();
        assert.true(this.controller.isAllSelected);
        this.controller.selectAll();
        assert.deepEqual(this.controller.selection, []);

        this.controller.selection = ['issue_open:a', 'issue_open:gone'];
        this.controller.pruneSelection();
        assert.deepEqual(this.controller.selection, ['issue_open:a'], 'keys that left the list are dropped');
    });

    test('shift-clicking a checkbox selects or clears every row between it and the last one toggled', async function (assert) {
        this.fetch.itemsResponse = { ...this.fetch.itemsResponse, items: ['a', 'b', 'c', 'd', 'e'].map((letter) => item(`issue_open:${letter}`)) };
        await this.controller.loadItems.perform();
        const [a, b, , d, e] = this.controller.items;

        this.controller.toggleSelect(b);
        this.controller.toggleSelect(e, { range: true });
        assert.deepEqual(this.controller.selection, ['issue_open:b', 'issue_open:c', 'issue_open:d', 'issue_open:e'], 'the range from b to e is selected');

        this.controller.toggleSelect(a, { range: true });
        assert.deepEqual(this.controller.selection, ['issue_open:a', 'issue_open:b', 'issue_open:c', 'issue_open:d', 'issue_open:e'], 'shift-clicking a selects back up to e');

        this.controller.toggleSelect(d);
        this.controller.toggleSelect(b, { range: true });
        assert.deepEqual(this.controller.selection, ['issue_open:a', 'issue_open:e'], 'unselecting d then shift-clicking the selected b clears b through d');

        this.controller.clearSelection();
        this.controller.toggleSelect(d, { range: true });
        assert.deepEqual(this.controller.selection, ['issue_open:d'], 'with no earlier row, shift-click selects just that row');
    });

    test('acknowledge patches the row in place and snooze takes it off the open tab', async function (assert) {
        const rows = [item('issue_open:a'), item('issue_open:b')];
        this.fetch.itemsResponse = { ...this.fetch.itemsResponse, items: rows, groups: [{ key: 'none', count: 2, items: rows }] };
        await this.controller.loadItems.perform();

        this.fetch.postResponse = { state: { status: 'acknowledged', acknowledged_by_name: 'Ada' } };
        await this.controller.act.perform(this.controller.items[0], 'acknowledge');

        const [, url, body] = this.fetch.calls.find(([method]) => method === 'post');
        assert.strictEqual(url, 'fleet-ops/radar/items/issue_open%3Aa/acknowledge');
        assert.deepEqual(body, {});
        assert.strictEqual(this.controller.items[0].state.status, 'acknowledged');
        assert.strictEqual(this.controller.groups[0].items[0].state.acknowledged_by_name, 'Ada');
        assert.strictEqual(this.controller.items.length, 2, 'an acknowledged item stays on the open tab');

        this.fetch.postResponse = { state: { status: 'snoozed', snoozed_until: '2026-09-15T10:00:00Z' } };
        this.controller.drawerItem = this.controller.items[1];
        await this.controller.act.perform(this.controller.items[1], 'snooze', { minutes: 60 });

        const [, , snoozeBody] = this.fetch.calls.at(-2);
        assert.deepEqual(snoozeBody, { minutes: 60 });
        assert.deepEqual(
            this.controller.items.map((row) => row.key),
            ['issue_open:a'],
            'a snoozed item leaves the open tab'
        );
        assert.strictEqual(this.controller.drawerItem, null, 'the drawer closes with it');
        assert.strictEqual(this.notifications.messages.at(-1)[0], 'success');
    });

    test('bulk actions post every selected key once and apply each result', async function (assert) {
        const rows = [item('issue_open:a'), item('issue_open:b'), item('issue_open:c')];
        this.fetch.itemsResponse = { ...this.fetch.itemsResponse, items: rows, groups: [{ key: 'none', count: 3, items: rows }] };
        await this.controller.loadItems.perform();

        this.controller.selection = ['issue_open:a', 'issue_open:b'];
        this.fetch.postResponse = {
            results: [
                { key: 'issue_open:a', ok: true, state: { status: 'snoozed', snoozed_until: '2026-09-15T10:00:00Z' } },
                { key: 'issue_open:b', ok: false, error: 'not found' },
            ],
        };
        await this.controller.bulkAct.perform('snooze', { minutes: 60 });

        const [, url, body] = this.fetch.calls.find(([method]) => method === 'post');
        assert.strictEqual(url, 'fleet-ops/radar/items/bulk');
        assert.deepEqual(body, { keys: ['issue_open:a', 'issue_open:b'], action: 'snooze', minutes: 60, until: undefined });
        assert.deepEqual(
            this.controller.items.map((row) => row.key),
            ['issue_open:b', 'issue_open:c'],
            'the snoozed key left the open tab, the failed one stayed'
        );
        assert.deepEqual(this.controller.selection, [], 'the selection clears after a bulk action');
        assert.strictEqual(this.notifications.messages.at(-1)[0], 'success');

        this.controller.selection = ['issue_open:c'];
        this.controller.keyboardHandlers.acknowledge();
        await this.controller.bulkAct.last;
        const [, , ackBody] = this.fetch.calls.at(-2);
        assert.deepEqual(ackBody, { keys: ['issue_open:c'], action: 'acknowledge' }, 'with a selection the E key runs the bulk action');
    });

    test('confirming a decision makes its call, keeps an undoable strip and drops the card; not now snoozes its keys', async function (assert) {
        await this.controller.loadBriefing.perform();
        assert.strictEqual(this.controller.briefing.decisions.length, 1);

        const decision = { key: 'd1', title: 'Book TRK-118', keys: ['issue_open:a'] };
        this.fetch.postResponse = {};
        await this.controller.confirmDecision.perform(decision, {
            label: 'Confirm',
            method: 'POST',
            endpoint: 'maintenance-schedules/s/trigger',
            body: {},
            undo: { endpoint: 'undo-it', method: 'POST' },
        });

        assert.deepEqual(this.fetch.calls.find(([method]) => method === 'post').slice(1), ['maintenance-schedules/s/trigger', {}]);
        assert.strictEqual(this.controller.strips[0].kind, 'confirmed');
        assert.strictEqual(this.controller.strips[0].title, 'Book TRK-118');
        assert.deepEqual(this.controller.briefing.decisions, [], 'the card left the stack');

        await this.controller.undoStrip.perform(this.controller.strips[0]);
        assert.deepEqual(this.fetch.calls.filter(([, url]) => url === 'undo-it').length, 1);
        assert.deepEqual(this.controller.strips, []);

        this.fetch.postResponse = { results: [{ key: 'issue_open:a', ok: true, state: { status: 'snoozed', snoozed_until: '2026-09-16T08:35:00Z' } }] };
        await this.controller.dismissDecision.perform({ key: 'd2', title: 'Later', keys: ['issue_open:a'] });
        const [, url, body] = this.fetch.calls.find(([method, calledUrl]) => method === 'post' && calledUrl === 'fleet-ops/radar/items/bulk');
        assert.strictEqual(url, 'fleet-ops/radar/items/bulk');
        assert.deepEqual(body, { keys: ['issue_open:a'], action: 'snooze', minutes: 1440 });
        assert.strictEqual(this.controller.strips[0].kind, 'snoozed');
        assert.strictEqual(this.controller.strips[0].until, '2026-09-16T08:35:00Z');

        this.controller.filterCategory('maintenance');
        assert.strictEqual(this.controller.category, 'maintenance');
        assert.true(this.controller.isFiltered);
        this.controller.filterCategory('maintenance');
        assert.strictEqual(this.controller.category, '', 'filtering the same category again clears it');
    });

    test('the agenda view loads on demand, plans dropped items and drives the handover card', async function (assert) {
        this.controller.setView('agenda');
        await this.controller.loadAgenda.last;
        assert.strictEqual(this.controller.agenda.window.key, '24h');
        assert.deepEqual(this.fetch.calls.at(-1), ['get', 'fleet-ops/radar/agenda', { window: '24h', fleet: '' }]);

        this.controller.setWindow('7d');
        await this.controller.loadAgenda.last;
        assert.strictEqual(this.controller.window, '7d');

        await this.controller.planItem.perform('issue_open:a', '2026-09-15T16:00:00Z');
        const plan = this.fetch.calls.find(([method, url]) => method === 'post' && url.includes('/plan'));
        assert.deepEqual(plan.slice(1), ['fleet-ops/radar/items/issue_open%3Aa/plan', { planned_at: '2026-09-15T16:00:00Z' }]);

        await this.controller.openHandover.perform('shift_handover:d');
        assert.strictEqual(this.controller.handover.driver.label, 'Luis');

        await this.controller.reassignOrders.perform(this.controller.handover);
        const reassign = this.fetch.calls.find(([method]) => method === 'patch');
        assert.deepEqual(reassign.slice(1), ['orders/bulk-assign-driver', { ids: ['o1', 'o2'], driver: 'driver-alves' }]);
        assert.strictEqual(this.controller.handover, null, 'the card closes after reassigning');

        await this.controller.openHandover.perform('shift_handover:d');
        await this.controller.extendShift.perform(this.controller.handover, 60);
        const extend = this.fetch.calls.find(([method, url]) => method === 'post' && url.includes('/extend'));
        assert.deepEqual(extend.slice(1), ['fleet-ops/radar/shifts/sh1/extend', { minutes: 60 }]);

        this.controller.openAgendaEntry({ key: 'issue_open:zzz', rule: 'issue_open', title: 'Entry', severity: 'warning', actions: [], state: { status: 'open' } });
        assert.strictEqual(this.controller.drawerItem.key, 'issue_open:zzz', 'an entry the list does not have opens from its own fields');
    });

    test('a failed state action reports the error and leaves the row alone', async function (assert) {
        const rows = [item('issue_open:a')];
        this.fetch.itemsResponse = { ...this.fetch.itemsResponse, items: rows, groups: [{ key: 'none', count: 1, items: rows }] };
        await this.controller.loadItems.perform();

        this.fetch.failNextPost = true;
        await this.controller.act.perform(this.controller.items[0], 'acknowledge');

        assert.strictEqual(this.controller.items[0].state.status, 'open');
        assert.deepEqual(this.notifications.messages.at(-1), ['error', 'nope']);
    });

    test('open record loads the record by public id and opens its resource panel', async function (assert) {
        const hostRouter = this.owner.lookup('service:host-router');
        const store = this.owner.lookup('service:store');
        const issue = { id: 'uuid-a', public_id: 'issue_a' };
        const queries = [];
        const opened = [];
        store.queryRecord = async (modelName, query) => {
            queries.push([modelName, query]);
            return issue;
        };
        this.owner.lookup('service:issue-actions').panel = { view: (record) => opened.push(record) };

        await this.controller.openRecord(item('issue_open:a', { record: { route: 'management.issues.index.details', model: 'issue_a' } }));

        assert.deepEqual(
            queries,
            [['issue', { public_id: 'issue_a', single: true, with: ['driver', 'vehicle', 'assignee', 'reporter', 'order', 'files'] }]],
            'queried by public id, like the details route'
        );
        assert.deepEqual(opened, [issue], 'the issue panel opened with the loaded record');

        await this.controller.openRecord(item('notice:n', { record: null }));
        assert.strictEqual(opened.length, 1, 'an item without a record opens nothing');

        await this.controller.openRecord(item('x:y', { record: { route: 'operations.orders.index.details', model: 'order_1' } }));
        assert.deepEqual(hostRouter.transitions, [['console.fleet-ops.operations.orders.index.details', 'order_1']], 'a record with no panel is navigated to');
    });

    test('the keyboard handlers cover move, select, acknowledge, snooze, assign, open and close', function (assert) {
        const handlers = this.controller.keyboardHandlers;

        assert.deepEqual(Object.keys(handlers).sort(), ['acknowledge', 'assign', 'close', 'next', 'open', 'previous', 'search', 'select', 'snooze']);
        for (const handler of Object.values(handlers)) {
            assert.strictEqual(typeof handler, 'function');
        }
    });
});
