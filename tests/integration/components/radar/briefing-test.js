import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render, click } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import { setupIntl } from 'ember-intl/test-support';

function briefing(extra = {}) {
    return {
        score: { value: 78, delta: -4, summary: '3 overdue · 12 due this week' },
        categories: [
            {
                key: 'maintenance',
                label: 'Maintenance',
                score: 70,
                count: 3,
                critical: 1,
                gaps: [{ rule: 'maintenance_overdue', count: 3, label: '3 overdue' }],
                filter: { category: 'maintenance' },
            },
            { key: 'issues', label: 'Issues', score: 100, count: 0, critical: 0, gaps: [], filter: { category: 'issues' } },
        ],
        brief: [
            [{ text: '2 jobs are past due: ' }, { text: 'TRK-118', route: 'maintenance.schedules.index.details', model: 'schedule_oil' }, { text: ' (3d overdue).' }],
            [{ text: 'Everything else is routine.' }],
        ],
        decisions: [
            {
                key: 'open_work_order:schedule_oil',
                severity: 'critical',
                category: 'maintenance',
                title: 'Open a work order for TRK-118',
                subtitle: 'Oil change 3d overdue',
                reasoning: [{ text: 'TRK-118', route: 'maintenance.schedules.index.details', model: 'schedule_oil' }, { text: ' is 3d overdue.' }],
                confirm: { label: 'Open work order', action: 'create_work_order', method: 'POST', endpoint: 'maintenance-schedules/schedule_oil/trigger', body: {} },
                alternatives: [{ label: 'Issue only', action: 'create_issue_from_inspection', method: 'POST', endpoint: 'x', body: {} }],
                keys: ['maintenance_overdue:schedule_oil'],
                subject: null,
                record: { route: 'maintenance.schedules.index.details', model: 'schedule_oil' },
            },
        ],
        yesterday: { closed: 11, rolled_over: 2 },
        generated_at: '2026-09-15T08:30:00Z',
        ...extra,
    };
}

module('Integration | Component | radar/briefing', function (hooks) {
    setupRenderingTest(hooks);
    setupIntl(hooks, 'en-us');

    hooks.beforeEach(function () {
        this.calls = [];
        this.briefing = briefing();
        this.strips = [];
        this.collapsed = false;
        this.onToggle = () => this.calls.push(['toggle']);
        this.onFilterCategory = (category) => this.calls.push(['filter', category]);
        this.onConfirm = (decision, call) => this.calls.push(['confirm', decision.key, call.endpoint]);
        this.onAlternative = (decision, alternative) => this.calls.push(['alternative', decision.key, alternative.action]);
        this.onDismiss = (decision) => this.calls.push(['dismiss', decision.key]);
        this.onUndo = (strip) => this.calls.push(['undo', strip.key]);
        this.onWake = (strip) => this.calls.push(['wake', strip.key]);
        this.onOpenRecord = (record) => this.calls.push(['open', record.model]);
    });

    test('it renders the score, category rows, prose with links and the decision stack', async function (assert) {
        await render(
            hbs`<Radar::Briefing @briefing={{this.briefing}} @strips={{this.strips}} @collapsed={{this.collapsed}} @onToggle={{this.onToggle}} @onFilterCategory={{this.onFilterCategory}} @onConfirm={{this.onConfirm}} @onAlternative={{this.onAlternative}} @onDismiss={{this.onDismiss}} @onUndo={{this.onUndo}} @onWake={{this.onWake}} @onOpenRecord={{this.onOpenRecord}} />`
        );

        assert.dom('[data-test-radar-briefing-score] .fleet-ops-radar-briefing-score-number').hasText('78');
        assert.dom('[data-test-radar-briefing-score] .status-badge').hasClass('error-status-badge');
        assert.dom('[data-test-radar-briefing-score]').includesText('▼ 4 vs yesterday');
        assert.dom('[data-test-radar-briefing-yesterday]').hasText('Yesterday: 11 gaps closed, 2 rolled over');
        assert.dom('[data-test-radar-briefing-category="maintenance"] .fleet-ops-radar-briefing-category-score').hasText('70');
        assert.dom('[data-test-radar-briefing-category="maintenance"] .fleet-ops-radar-briefing-category-bar > span').hasClass('is-warn');
        assert.dom('[data-test-radar-briefing-category="maintenance"]').includesText('3 overdue');
        assert.dom('[data-test-radar-briefing-filter="issues"]').doesNotExist('a category with nothing open has nothing to filter');
        assert.dom('[data-test-radar-briefing-prose]').includesText('2 jobs are past due: TRK-118 (3d overdue). Everything else is routine.');
        assert.dom('[data-test-radar-briefing-prose] .fleet-ops-radar-briefing-link').hasText('TRK-118');
        assert.dom('[data-test-radar-decision="open_work_order:schedule_oil"]').exists();
        assert.dom('[data-test-radar-briefing-decisions]').includesText('1 of 1');

        await click('[data-test-radar-briefing-filter="maintenance"]');
        await click('[data-test-radar-briefing-prose] .fleet-ops-radar-briefing-link');
        await click('[data-test-radar-decision-confirm]');
        await click('[data-test-radar-decision-alternative="create_issue_from_inspection"]');
        await click('[data-test-radar-decision-dismiss]');
        await click('[data-test-radar-decision-open]');

        assert.deepEqual(this.calls, [
            ['filter', 'maintenance'],
            ['open', 'schedule_oil'],
            ['confirm', 'open_work_order:schedule_oil', 'maintenance-schedules/schedule_oil/trigger'],
            ['alternative', 'open_work_order:schedule_oil', 'create_issue_from_inspection'],
            ['dismiss', 'open_work_order:schedule_oil'],
            ['open', 'schedule_oil'],
        ]);
    });

    test('strips show what was confirmed or snoozed, and the brief collapses', async function (assert) {
        this.briefing = briefing({ decisions: [], score: { value: 100, delta: null, summary: 'nothing open' } });
        this.strips = [
            { kind: 'confirmed', key: 'a', title: 'Booked TRK-118 oil change', at: '2026-09-15T08:33:00Z', undo: { endpoint: 'x' } },
            { kind: 'snoozed', key: 'b', title: 'Warranty reminder', until: '2026-09-18T08:00:00Z', keys: ['k'] },
        ];

        await render(
            hbs`<Radar::Briefing @briefing={{this.briefing}} @strips={{this.strips}} @collapsed={{this.collapsed}} @onToggle={{this.onToggle}} @onFilterCategory={{this.onFilterCategory}} @onConfirm={{this.onConfirm}} @onAlternative={{this.onAlternative}} @onDismiss={{this.onDismiss}} @onUndo={{this.onUndo}} @onWake={{this.onWake}} @onOpenRecord={{this.onOpenRecord}} />`
        );

        assert.dom('[data-test-radar-briefing-nothing]').exists();
        assert.dom('[data-test-radar-briefing-score]').includesText('first reading');
        assert.dom('[data-test-radar-briefing-strip="confirmed"]').includesText('Booked TRK-118 oil change');
        assert.dom('[data-test-radar-briefing-strip="snoozed"]').includesText('Warranty reminder');

        await click('[data-test-radar-briefing-undo]');
        await click('[data-test-radar-briefing-wake]');
        assert.deepEqual(this.calls, [
            ['undo', 'a'],
            ['wake', 'b'],
        ]);

        this.set('collapsed', true);
        assert.dom('[data-test-radar-briefing-score]').doesNotExist('collapsed hides the body');
        await click('[data-test-radar-briefing-toggle]');
        assert.deepEqual(this.calls.at(-1), ['toggle']);
    });
});
