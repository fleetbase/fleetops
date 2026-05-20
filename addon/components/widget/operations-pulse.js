import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { debug } from '@ember/debug';
import { task } from 'ember-concurrency';

const TILE_ORDER = ['active_orders', 'completed_today', 'drivers_online', 'vehicles_deployed', 'issues_open'];

/**
 * Each tile gets:
 *  - icon: FA icon name + colored chip
 *  - accent: CSS class on the tile root for the gradient + accent stripe palette
 *  - deltaInverse: flip success/danger semantics (for "lower-is-better" metrics)
 */
const TILE_META = {
    active_orders:     { icon: 'bolt',                 accent: 'pulse-accent-blue'   },
    completed_today:   { icon: 'circle-check',         accent: 'pulse-accent-green'  },
    drivers_online:    { icon: 'id-card',              accent: 'pulse-accent-sky'    },
    vehicles_deployed: { icon: 'truck',                accent: 'pulse-accent-indigo' },
    issues_open:       { icon: 'triangle-exclamation', accent: 'pulse-accent-amber', deltaInverse: true },
};

/** Refetch the full snapshot this often as a reconciliation guard against
 * drift between incrementally-applied socket deltas and authoritative state. */
const RECONCILE_INTERVAL_MS = 60_000;

/**
 * Live operational snapshot. Initial GET seeds the tiles, then subscribes to
 * `company.{uuid}` and increments/decrements counters on order.* events
 * without polling. Reconciles via a full refetch every 60s.
 */
export default class WidgetOperationsPulseComponent extends Component {
    static widgetId = 'fleet-ops-operations-pulse-widget';

    @service fetch;
    @service socket;
    @service currentUser;

    @tracked data = null;
    @tracked error = null;

    socketChannel = null;
    socketActive = false;
    reconcileTimer = null;

    constructor() {
        super(...arguments);
        this.load.perform();
        this.subscribe();
        this.reconcileTimer = setInterval(() => this.load.perform(), RECONCILE_INTERVAL_MS);
    }

    willDestroy() {
        super.willDestroy(...arguments);
        this.unsubscribe();
        if (this.reconcileTimer) clearInterval(this.reconcileTimer);
    }

    get tiles() {
        if (!this.data) return [];
        return TILE_ORDER
            .filter((key) => this.data[key])
            .map((key) => ({
                key,
                title: this.titleFor(key),
                ...this.data[key],
                meta: TILE_META[key] ?? {},
            }));
    }

    titleFor(key) {
        return key.split('_').map((p) => p.charAt(0).toUpperCase() + p.slice(1)).join(' ');
    }

    deltaStatus(tile) {
        if (typeof tile.delta_pct !== 'number' || tile.delta_pct === 0) return null;
        const positive = tile.delta_pct > 0;
        const isGood = tile.meta?.deltaInverse ? !positive : positive;
        return isGood ? 'success' : 'danger';
    }

    deltaText(tile) {
        if (typeof tile.delta_pct !== 'number') return null;
        const sign = tile.delta_pct > 0 ? '+' : '';
        return `${sign}${tile.delta_pct}%`;
    }

    @task *load() {
        try {
            this.data = yield this.fetch.get('fleet-ops/analytics/operations-pulse');
            this.error = null;
        } catch (e) {
            this.error = e?.message ?? 'Failed to load operations pulse';
        }
    }

    @action
    refresh() {
        this.load.perform();
    }

    async subscribe() {
        const companyId = this.currentUser?.companyId;
        if (!companyId) return;

        try {
            const sc = this.socket.instance();
            const channel = sc.subscribe(`company.${companyId}`);
            this.socketChannel = channel;
            this.socketActive = true;

            if (channel.state !== 'subscribed') {
                await channel.subscribe();
            }

            for await (const msg of channel) {
                if (!this.socketActive) break;
                this.applyEvent(msg);
            }
        } catch (e) {
            debug(`[operations-pulse] socket error: ${e?.message ?? e}`);
        }
    }

    async unsubscribe() {
        this.socketActive = false;
        try {
            await this.socketChannel?.unsubscribe();
        } catch (e) {
            debug(`[operations-pulse] unsubscribe error: ${e?.message ?? e}`);
        }
        this.socketChannel = null;
    }

    applyEvent(msg) {
        if (!this.data || !msg) return;
        const event = msg.event;
        const next = { ...this.data };

        const adjust = (key, delta) => {
            if (!next[key]) return;
            next[key] = { ...next[key], value: Math.max(0, (next[key].value ?? 0) + delta) };
        };

        switch (event) {
            case 'order.created':
            case 'order.dispatched':
            case 'order.driver_assigned':
                adjust('active_orders', +1);
                break;
            case 'order.completed':
                adjust('active_orders', -1);
                adjust('completed_today', +1);
                break;
            case 'order.canceled':
            case 'order.failed':
                adjust('active_orders', -1);
                break;
            case 'issue.created':
                adjust('issues_open', +1);
                break;
            case 'issue.resolved':
                adjust('issues_open', -1);
                break;
            default:
                return;
        }

        this.data = next;
    }
}
