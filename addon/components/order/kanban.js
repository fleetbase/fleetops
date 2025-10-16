import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { isArray } from '@ember/array';
import { debug } from '@ember/debug';
import { task } from 'ember-concurrency';
import titleize from 'ember-cli-string-helpers/utils/titleize';
import smartHumanize from '@fleetbase/ember-ui/utils/smart-humanize';
import isUuid from '@fleetbase/ember-core/utils/is-uuid';

export default class OrderKanbanComponent extends Component {
    @service fetch;
    @service notifications;
    @service intl;
    @tracked statuses = [];
    @tracked orders = this.args.orders ?? [];
    @tracked orderConfig = this.args.orderConfig ?? null;

    #defaultStatuses = {
        start: ['created', 'dispatched', 'started'],
        end: ['completed', 'canceled'],
    };

    get columns() {
        const start = this.#defaultStatuses.start ?? [];
        const end = this.#defaultStatuses.end ?? [];
        const loaded = isArray(this.statuses) ? this.statuses : [];

        const startSet = new Set(start);
        const endSet = new Set(end);

        // Middle = loaded statuses that are NOT in start/end, preserving their order & uniqueness
        const seen = new Set(start); // we've already included start
        const middle = [];
        for (const s of loaded) {
            if (!s) continue; // skip falsy
            if (startSet.has(s)) continue; // skip if belongs to start block
            if (endSet.has(s)) continue; // skip if belongs to end block (we’ll add end later in fixed order)
            if (!seen.has(s)) {
                seen.add(s);
                middle.push(s);
            }
        }

        // Build final ordered list: start → middle → end (deduped, in fixed end order)
        const final = [...start, ...middle];
        for (const s of end) {
            if (!final.includes(s)) final.push(s);
        }

        return final.map((status, index) => ({
            id: status,
            title: titleize(smartHumanize(status)),
            position: index,
            cards: this.#getOrdersByStatus(status, this.orders),
        }));
    }

    constructor() {
        super(...arguments);
        this.loadStatuses.perform();
    }

    /* eslint-disable no-unused-vars */
    @action async handleCardMove(card, targetColumnId, targetPosition, sourceColumnId) {
        // work against the array the UI renders from
        const order = this.orders.find((o) => o.id === card.id);
        if (!order) {
            console.error('Order not found in this.orders:', card.id);
            return;
        }

        const prevStatus = order.status;

        // First check avaiilable activities, if the `targetColumnId` is one, update the order activity.
        // If it's not an available status, show an error notification
        try {
            const nextActivities = await this.fetch.get(`orders/next-activity/${order.id}`);
            if (isArray(nextActivities)) {
                const activity = nextActivities.find((activity) => activity.code === targetColumnId);
                if (activity) {
                    try {
                        await this.fetch.patch(`orders/update-activity/${order.id}`, { activity });
                        this.notifications.success(this.intl.t('order.kanban.status-updated', { status: targetColumnId }));
                        order.status = targetColumnId;
                        // replace the tracked array to notify Glimmer
                        // (this keeps the *same* object reference, which is fine)
                        this.orders = this.orders.slice();
                    } catch (err) {
                        this.notifications.serverError(err);
                    }
                } else {
                    this.notifications.warning(this.intl.t('order.kanban.cannot-update-status', { status: targetColumnId }));
                }
            }
        } catch (err) {
            this.notifications.warning(this.intl.t('order.kanban.cannot-update-status', { status: targetColumnId }));
        }
    }

    @action handleArgsChange(el, [orderConfig, orders = []]) {
        if (isArray(orders)) {
            this.orders = orders;
        }

        if (isUuid(orderConfig)) {
            this.orderConfig = orderConfig;
        } else {
            this.orderConfig = null;
        }
        this.loadStatuses.perform();
    }

    #getOrdersByStatus(status, orders = []) {
        let filteredOrders = orders.filter((order) => order.status === status);
        if (this.orderConfig) {
            filteredOrders = filteredOrders.filter((order) => order.order_config_uuid === this.orderConfig);
        }

        return filteredOrders;
    }

    @task *loadStatuses() {
        const params = {};
        if (this.orderConfig) params.order_config_uuid = this.orderConfig;

        try {
            const statuses = yield this.fetch.get('orders/statuses', params);
            this.statuses = isArray(statuses) ? statuses : [];
        } catch (err) {
            debug('Unable to load order statuses: ' + err.message);
        }
    }
}
