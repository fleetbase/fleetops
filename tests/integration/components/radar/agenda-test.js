import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render, click, triggerEvent } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import { setupIntl } from 'ember-intl/test-support';

function agenda() {
    const driver = { type: 'driver', uuid: 'driver-ortega', public_id: 'driver_ortega', label: 'Luis Ortega' };

    return {
        now: '2026-09-15T08:35:00',
        window: {
            key: '24h',
            hours: 24,
            start_at: '2026-09-15T08:00:00Z',
            end_at: '2026-09-16T08:00:00Z',
            now_pct: 2.43,
            ticks: [
                { at: '2026-09-15T08:00:00Z', label: '08', pct: 0 },
                { at: '2026-09-15T20:00:00Z', label: '20', pct: 50 },
            ],
        },
        lanes: {
            shifts: [
                {
                    kind: 'shift',
                    key: 'shift:shift_1',
                    driver,
                    status: 'in_progress',
                    state: 'on_shift',
                    at: '2026-09-15T01:15:00Z',
                    end_at: '2026-09-15T09:15:00Z',
                    label: '01:15–09:15',
                    pct: 0,
                    width_pct: 5.2,
                    lane: 'shifts',
                    severity: 'warning',
                    gap_keys: ['shift_handover:driver_ortega'],
                    handover_key: 'shift_handover:driver_ortega',
                    active_orders: 2,
                },
                {
                    kind: 'item',
                    key: 'shift_handover:driver_ortega',
                    rule: 'shift_handover',
                    chip: 'Handover',
                    category: 'staffing',
                    severity: 'warning',
                    title: 'Luis Ortega shift ends in 40m',
                    subject: driver,
                    lane: 'shifts',
                    at: '2026-09-15T09:15:00Z',
                    label: '09:15',
                    planned: false,
                    pct: 5.2,
                    state: { status: 'open' },
                    actions: ['handover'],
                    record: null,
                },
            ],
            maintenance: [
                {
                    kind: 'item',
                    key: 'maintenance_due_soon:s',
                    rule: 'maintenance_due_soon',
                    chip: 'Maint',
                    category: 'maintenance',
                    severity: 'warning',
                    title: 'Tire rotation due today',
                    subject: { type: 'vehicle', label: 'TRK-204' },
                    lane: 'maintenance',
                    at: '2026-09-15T14:00:00Z',
                    label: '14:00',
                    planned: false,
                    pct: 25,
                    state: { status: 'open' },
                    actions: [],
                    record: null,
                },
            ],
            expiries: [],
            notices: [],
        },
        overdue: [
            {
                kind: 'item',
                key: 'maintenance_overdue:o',
                rule: 'maintenance_overdue',
                chip: 'Maint',
                category: 'maintenance',
                severity: 'critical',
                title: 'Oil change 3d overdue',
                subject: { type: 'vehicle', label: 'TRK-118' },
                lane: 'maintenance',
                at: '2026-09-12T08:00:00Z',
                label: '08:00',
                planned: false,
                pct: 0,
                state: { status: 'open' },
                actions: [],
                record: null,
            },
        ],
        later: [],
        anytime: [
            {
                kind: 'item',
                key: 'issue_open:i',
                rule: 'issue_open',
                chip: 'Issue',
                category: 'issues',
                severity: 'warning',
                title: 'Check engine light',
                subject: { type: 'vehicle', label: 'TRK-311' },
                lane: 'maintenance',
                at: null,
                label: null,
                planned: false,
                pct: null,
                state: { status: 'open' },
                actions: [],
                record: null,
            },
        ],
        handovers: [],
        counts: { overdue: 1, later: 0, anytime: 1, shifts: 1 },
    };
}

module('Integration | Component | radar/agenda', function (hooks) {
    setupRenderingTest(hooks);
    setupIntl(hooks, 'en-us');

    hooks.beforeEach(function () {
        this.calls = [];
        this.agenda = agenda();
        this.window = '24h';
        this.onChangeWindow = (window) => this.calls.push(['window', window]);
        this.onOpen = (entry) => this.calls.push(['open', entry.key]);
        this.onPlan = (key, at, lane) => this.calls.push(['plan', key, at, lane]);
        this.onHandover = (key) => this.calls.push(['handover', key]);
        this.onSeeAll = () => this.calls.push(['see-all']);
    });

    test('it draws the overdue band, the four lanes with shifts and entries, and the tray', async function (assert) {
        await render(
            hbs`<Radar::Agenda @agenda={{this.agenda}} @window={{this.window}} @onChangeWindow={{this.onChangeWindow}} @onOpen={{this.onOpen}} @onPlan={{this.onPlan}} @onHandover={{this.onHandover}} @onSeeAll={{this.onSeeAll}} />`
        );

        assert.dom('[data-test-radar-agenda-window="24h"]').hasClass('is-active');
        assert.dom('[data-test-radar-agenda-summary]').hasText('1 shifts in window · 1 overdue · 1 undated');
        assert.dom('[data-test-radar-agenda-overdue] [data-test-radar-agenda-entry="maintenance_overdue:o"]').exists();
        assert.dom('[data-test-radar-agenda-lane]').exists({ count: 4 });
        assert.dom('[data-test-radar-agenda-lane="shifts"] [data-test-radar-agenda-shift="shift:shift_1"]').hasClass('has-handover');
        assert.dom('[data-test-radar-agenda-lane="shifts"] [data-test-radar-agenda-shift="shift:shift_1"]').includesText('Luis Ortega');
        assert.dom('[data-test-radar-agenda-lane="maintenance"] [data-test-radar-agenda-entry="maintenance_due_soon:s"]').hasAttribute('style', /left: 25%/);
        assert.dom('[data-test-radar-agenda-lane="expiries"] .fleet-ops-radar-agenda-empty-lane').hasText('No events in this window.');
        assert.dom('[data-test-radar-agenda-tray] [data-test-radar-agenda-entry="issue_open:i"]').hasAttribute('draggable', 'true');
        assert.dom('.fleet-ops-radar-agenda-now em').hasText('08:35');

        await click('[data-test-radar-agenda-window="7d"]');
        await click('[data-test-radar-agenda-shift="shift:shift_1"]');
        await click('[data-test-radar-agenda-lane="maintenance"] [data-test-radar-agenda-entry="maintenance_due_soon:s"]');
        await click('[data-test-radar-agenda-lane="shifts"] [data-test-radar-agenda-entry="shift_handover:driver_ortega"]');
        await click('[data-test-radar-agenda-see-all]');

        assert.deepEqual(this.calls, [
            ['window', '7d'],
            ['handover', 'shift_handover:driver_ortega'],
            ['open', 'maintenance_due_soon:s'],
            ['handover', 'shift_handover:driver_ortega'],
            ['see-all'],
        ]);
    });

    test('dropping a tray item on a lane plans it at the time under the cursor', async function (assert) {
        await render(
            hbs`<Radar::Agenda @agenda={{this.agenda}} @window={{this.window}} @onChangeWindow={{this.onChangeWindow}} @onOpen={{this.onOpen}} @onPlan={{this.onPlan}} @onHandover={{this.onHandover}} @onSeeAll={{this.onSeeAll}} />`
        );

        const track = this.element.querySelector('[data-test-radar-agenda-lane="notices"] [data-test-radar-agenda-track]');
        const rect = track.getBoundingClientRect();
        const store = {};
        const dataTransfer = {
            types: ['text/radar-key'],
            setData: (type, value) => (store[type] = value),
            getData: (type) => store[type],
            effectAllowed: 'move',
        };

        await triggerEvent('[data-test-radar-agenda-tray] [data-test-radar-agenda-entry="issue_open:i"]', 'dragstart', { dataTransfer });
        await triggerEvent(track, 'dragover', { dataTransfer });
        assert.dom('[data-test-radar-agenda-lane="notices"]').hasClass('is-drop-target');

        await triggerEvent(track, 'drop', { dataTransfer, clientX: rect.left + rect.width / 2, clientY: rect.top + 4 });
        assert.dom('[data-test-radar-agenda-lane="notices"]').doesNotHaveClass('is-drop-target');

        const [name, key, at, lane] = this.calls.at(-1);
        assert.strictEqual(name, 'plan');
        assert.strictEqual(key, 'issue_open:i');
        assert.strictEqual(lane, 'notices');
        assert.strictEqual(new Date(at).toISOString(), '2026-09-15T20:00:00.000Z', 'halfway across a 24h window is 12 hours in');
    });
});
