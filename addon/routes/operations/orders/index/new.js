import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class OperationsOrdersIndexNewRoute extends Route {
    @service notifications;
    @service hostRouter;
    @service abilities;
    @service intl;
    @service sidebar;
    @service store;

    queryParams = {
        repeat: { refreshModel: true },
        source_order: { refreshModel: true },
        source_series: { refreshModel: true },
    };

    @action willTransition() {
        if (this.controller) {
            this.controller.reset();
        }
    }

    activate() {
        this.sidebar.hide();
    }

    deactivate() {
        this.sidebar.show();
    }

    beforeModel() {
        if (this.abilities.cannot('fleet-ops create order')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('console.fleet-ops.operations.orders.index');
        }
    }

    async model({ source_order, source_series }) {
        if (source_series) {
            return {
                sourceSeries: await this.store.queryRecord('recurring-order-schedule', {
                    public_id: source_series,
                    single: true,
                    with: ['customer', 'facilitator', 'orderConfig', 'serviceRate', 'driverAssigned', 'vehicleAssigned'],
                    upcoming_limit: 25,
                    history_limit: 25,
                }),
            };
        }

        if (source_order) {
            return {
                sourceOrder: await this.store.queryRecord('order', {
                    public_id: source_order,
                    single: true,
                    with: ['payload', 'driverAssigned', 'vehicleAssigned', 'orderConfig', 'customer', 'facilitator'],
                }),
            };
        }

        return null;
    }

    setupController(controller, model, transition) {
        super.setupController(...arguments);
        controller.configureRepeatMode({
            repeat: transition.to.queryParams.repeat === true || transition.to.queryParams.repeat === 'true',
            sourceOrder: model?.sourceOrder,
            sourceOrderId: transition.to.queryParams.source_order,
            sourceSeries: model?.sourceSeries,
            sourceSeriesId: transition.to.queryParams.source_series,
        });
    }
}
