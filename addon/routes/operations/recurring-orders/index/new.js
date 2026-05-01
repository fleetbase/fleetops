import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class OperationsRecurringOrdersIndexNewRoute extends Route {
    @service store;

    queryParams = {
        from_order: { refreshModel: true },
    };

    async model(params) {
        const sourceOrder = params.from_order ? await this.store.findRecord('order', params.from_order) : null;
        return { sourceOrder };
    }

    setupController(controller, model) {
        super.setupController(controller, model);
        controller.sourceOrder = model.sourceOrder;
        controller.resetForm(model.sourceOrder);
    }
}
