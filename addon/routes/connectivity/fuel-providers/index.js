import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class ConnectivityFuelProvidersIndexRoute extends Route {
    @service store;

    queryParams = {
        page: { refreshModel: true },
        limit: { refreshModel: true },
        sort: { refreshModel: true },
        query: { refreshModel: true },
        provider: { refreshModel: true },
        status: { refreshModel: true },
        environment: { refreshModel: true },
    };

    model(params) {
        return this.store.query('fuel-provider-connection', { sort: '-updated_at', ...params });
    }
}
