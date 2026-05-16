import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class OperationsOrdersIndexSeriesRoute extends Route {
    @service store;

    queryParams = {
        page: { refreshModel: true },
        limit: { refreshModel: true },
        sort: { refreshModel: true },
        query: { refreshModel: true },
        status: { refreshModel: true },
    };

    model(params) {
        const query = {
            page: params.page ?? 1,
            limit: params.limit ?? 20,
            sort: params.sort ?? '-created_at',
        };

        if (params.query) {
            query.query = params.query;
        }

        if (params.status) {
            query.status = params.status;
        }

        return this.store.query('recurring-order-schedule', query);
    }

    setupController(controller, model) {
        super.setupController(...arguments);
        controller.index?.setSeriesCount(model?.meta?.total ?? model?.length ?? 0);
        controller.index.layout = 'series';
    }
}
