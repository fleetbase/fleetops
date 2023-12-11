import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class ManagementVehiclesIndexDetailsRoute extends Route {
    @service store;

    queryParams = {
        view: { refreshModel: false },
    };

    model(params) {
        return this.store.findRecord('vehicle', params.public_id);
    }

    afterModel(model) {
        model.loadDriver();
    }
}
