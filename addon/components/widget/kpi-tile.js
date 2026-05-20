import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';

/**
 * <Widget::KpiTile @slug="earnings" @title="..." @format="money" @showDelta={{true}}
 *                  @showSparkline={{true}} @deltaInverse={{false}} @period="30d" />
 *
 * Renders a single KPI tile backed by `/fleet-ops/metrics/{slug}`. Hosts the
 * format-specific value rendering, the optional period-over-period delta
 * badge (with `deltaInverse` to flip semantics for inverse-good metrics like
 * open_issues), and the optional sparkline line chart.
 */
export default class WidgetKpiTileComponent extends Component {
    @service fetch;
    @tracked data = null;
    @tracked error = null;

    get period() {
        return this.args.period ?? '30d';
    }

    get showDelta() {
        return this.args.showDelta ?? true;
    }

    get showSparkline() {
        return this.args.showSparkline ?? true;
    }

    get deltaInverse() {
        return this.args.deltaInverse ?? false;
    }

    get format() {
        return this.args.format ?? this.data?.format ?? 'count';
    }

    get currency() {
        return this.data?.currency ?? 'USD';
    }

    get deltaPct() {
        return this.data?.delta_pct;
    }

    get hasDelta() {
        return this.showDelta && typeof this.deltaPct === 'number';
    }

    get deltaStatus() {
        const pct = this.deltaPct;
        if (typeof pct !== 'number' || pct === 0) return 'info';
        const positive = pct > 0;
        const isGood = this.deltaInverse ? !positive : positive;
        return isGood ? 'success' : 'danger';
    }

    get deltaText() {
        const pct = this.deltaPct;
        const sign = pct > 0 ? '+' : '';
        return `${sign}${pct}%`;
    }

    /** Tints the card subtly based on trend direction so the dashboard pops. */
    get cardAccentClass() {
        const pct = this.deltaPct;
        if (typeof pct !== 'number' || pct === 0) {
            return 'kpi-accent-neutral';
        }
        const positive = pct > 0;
        const isGood = this.deltaInverse ? !positive : positive;
        return isGood ? 'kpi-accent-good' : 'kpi-accent-bad';
    }

    get valueTextClass() {
        const pct = this.deltaPct;
        if (typeof pct !== 'number' || pct === 0) {
            return 'text-black dark:text-gray-100';
        }
        const positive = pct > 0;
        const isGood = this.deltaInverse ? !positive : positive;
        return isGood ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400';
    }

    /** Neon palette per delta direction. Drives line + fill + value highlight. */
    get accentColors() {
        // Bright neon-ish trio that pops on the dark theme without being garish in light.
        const good = { line: '#22c55e', fill: 'rgba(34, 197, 94, 0.22)', glow: 'rgba(34, 197, 94, 0.35)' };
        const bad = { line: '#ef4444', fill: 'rgba(239, 68, 68, 0.22)', glow: 'rgba(239, 68, 68, 0.35)' };
        const neutral = { line: '#3485e2', fill: 'rgba(52, 133, 226, 0.18)', glow: 'rgba(52, 133, 226, 0.30)' };

        const pct = this.deltaPct;
        if (typeof pct !== 'number' || pct === 0) return neutral;
        const positive = pct > 0;
        const isGood = this.deltaInverse ? !positive : positive;
        return isGood ? good : bad;
    }

    get sparklineDatasets() {
        const points = this.data?.sparkline?.data;
        if (!points || points.length === 0) return null;

        const { line, fill } = this.accentColors;

        return [
            {
                data: points,
                borderColor: line,
                backgroundColor: fill,
                pointRadius: 0,
                borderWidth: 1.75,
                tension: 0.4,
                // Anchor the area fill to the chart's y=0 axis so the sparkline
                // visually sits at the BOTTOM of the card regardless of the data range.
                fill: 'origin',
            },
        ];
    }

    get sparklineLabels() {
        return this.data?.sparkline?.labels ?? [];
    }

    get sparklineOptions() {
        return {
            responsive: true,
            maintainAspectRatio: false,
            animation: { duration: 600, easing: 'easeOutQuart' },
            plugins: {
                legend: { display: false },
                tooltip: { enabled: false },
            },
            scales: {
                x: { display: false },
                // Lock min to 0 so flat-zero data sits flush along the bottom edge.
                // suggestedMin/Max keep the line off the very top/bottom for non-zero data.
                y: { display: false, min: 0, beginAtZero: true, grace: '5%' },
            },
            elements: { line: { borderJoinStyle: 'round' } },
        };
    }

    constructor() {
        super(...arguments);
        this.load.perform();
    }

    @task *load() {
        try {
            this.data = yield this.fetch.get(`fleet-ops/metrics/${this.args.slug}`, {
                period: this.period,
                sparkline: this.showSparkline,
                compare: this.showDelta,
            });
            this.error = null;
        } catch (e) {
            this.error = e?.message ?? 'Failed to load metric';
        }
    }

    @action
    refresh() {
        this.load.perform();
    }
}
