import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class ManagementIssuesIndexRoute extends Route {
    @service store;

    /**
     * Queryable parameters
     *
     * @var {Object}
     */
    queryParams = {
        page: { refreshModel: true },
        limit: { refreshModel: true },
        sort: { refreshModel: true },
        query: { refreshModel: true },
        country: { refreshModel: true },
        status: { refreshModel: true },
    };

    model(params) {
        return this.store.query('issue', { ...params });
    }
}
