import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class ManagementContactsIndexRoute extends Route {
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
        status: { refreshModel: true },
    };

    model(params) {
        return this.store.query('contact', params);
    }
}
