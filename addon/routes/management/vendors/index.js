import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class ManagementVendorsIndexRoute extends Route {
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
        name: { refreshModel: true },
        email: { refreshModel: true },
        address: { refreshModel: true },
        phone: { refreshModel: true },
        type: { refreshModel: true },
        createdAt: { refreshModel: true },
        updatedAt: { refreshModel: true },
        website_url: { refreshModel: true },
    };

    model(params) {
        return this.store.query('vendor', { ...params });
    }

    async setupController(controller, model) {
        console.log('setupController action called');
        super.setupController(...arguments);

        // load integrated vendors
        const integratedVendors = await this.store.findAll('integrated-vendor');

        // append integrated vendors
        controller.rows = [...model.toArray(), ...integratedVendors.toArray()];
    }
}
