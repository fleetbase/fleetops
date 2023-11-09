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
        name: { refreshModel: true },
        title: { refreshModel: true },
        phone: { refreshModel: true },
        email: { refreshModel: true },
        type: { refreshModel: true },
        internal_id: { refreshModel: true },
        createdAt: { refreshModel: true },
        updatedAt: { refreshModel: true },
    };

    model(params) {
        return this.store.query('contact', params);
    }
}
