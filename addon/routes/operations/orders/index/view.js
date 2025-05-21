import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { debug } from '@ember/debug';

export default class OperationsOrdersIndexViewRoute extends Route {
    @service currentUser;
    @service notifications;
    @service store;
    @service socket;
    @service hostRouter;
    @service abilities;
    @service intl;

    @action willTransition() {
        if (this.controller) {
            this.controller.resetView();
        }
    }

    @action error(error) {
        this.notifications.serverError(error);
        return this.transitionTo('operations.orders.index');
    }

    beforeModel() {
        if (this.abilities.cannot('fleet-ops view order')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('console.fleet-ops.operations.orders.index');
        }
    }

    model({ public_id }) {
        return this.store.queryRecord('order', {
            public_id,
            single: true,
            with: ['payload', 'driverAssigned', 'orderConfig', 'customer', 'facilitator', 'trackingStatuses', 'trackingNumber', 'purchaseRate', 'comments', 'files'],
        });
    }

    /**
     * Handle resolved model
     *
     * @param {OrderModel} model
     * @memberof OperationsOrdersIndexViewRoute
     */
    afterModel(model) {
        this.listenForOrderEvents(model);
    }

    /**
     * Listen to order channel for update events to refresh data
     *
     * @param {OrderModel} model
     * @memberof OperationsOrdersIndexViewRoute
     */
    async listenForOrderEvents(model) {
        // Get socket instance
        const socket = this.socket.instance();

        // The channel ID to listen on
        const channelId = `order.${model.public_id}`;

        // Listed on company channel
        const channel = socket.subscribe(channelId);

        // Listen for channel subscription
        (async () => {
            for await (let output of channel) {
                const { event, data } = output;

                // debug output
                debug(`Socket Event : ${event} : ${JSON.stringify(output)}`);

                // Only reload if the order has a status change stemming from an updated event OR
                // if a waypoint has been completed which will trigger `order.completed`
                const statusChanged = event === 'order.updated' && data.status !== model.status;
                const shouldReload = ['order.completed', 'waypoint.activity', 'order.created'].includes(event);
                if (statusChanged || shouldReload) {
                    this.refresh();

                    // reload the controller stuff as well
                    if (this.controller) {
                        this.controller.loadOrderRelations.perform(model);
                    }
                }

                if (typeof this.onOrderEvent === 'function') {
                    this.onOrderEvent(output);
                }
            }
        })();

        // disconnect when transitioning
        this.on('willTransition', () => {
            channel.close();
        });
    }

    async setupController(controller, model) {
        super.setupController(controller, model);
        controller.loadOrderRelations.perform(model);
    }
}
