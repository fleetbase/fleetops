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
        priority: { refreshModel: true },
        status: { refreshModel: true },
        vehicle: { refreshModel: true },
        driver: { refreshModel: true },
        assignee: { refreshModel: true },
        reporter: { refreshModel: true },
        type: { refreshModel: true },
        category: { refreshModel: true },
        updatedAt: { refreshModel: true },
        createdAt: { refreshModel: true },
    };

    model(params) {
        return this.store.query('issue', { ...params, with: ['driver', 'vehicle', 'assignee', 'reporter'] });
    }
}
