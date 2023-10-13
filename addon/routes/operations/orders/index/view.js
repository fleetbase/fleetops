import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class OperationsOrdersIndexViewRoute extends Route {
    @service currentUser;
    @service notifications;
    @service store;
    @service socket;

    @action willTransition(transition) {
        const shouldReset = typeof transition.to.name === 'string' && !transition.to.name.includes('operations.orders');

        if (this.controller && shouldReset) {
            this.controller.resetView();
        }
    }

    @action error(error) {
        this.notifications.serverError(error);
        return this.transitionTo('operations.orders.index');
    }

    model(order) {
        const { public_id } = order;

        return this.store.queryRecord('order', {
            public_id,
            single: true,
            with: ['payload', 'driverAssigned', 'customer', 'facilitator', 'trackingStatuses', 'trackingNumber'],
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
                this.refresh();

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

        controller.isLoadingAdditionalData = true;

        await model.loadPayload();
        await model.loadDriver();
        await model.loadTrackingNumber();
        await model.loadCustomer();
        await model.loadTrackingActivity();
        await model.loadOrderConfig();

        controller.isLoadingAdditionalData = false;
    }
}
