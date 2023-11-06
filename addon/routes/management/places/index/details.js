import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class ManagementPlacesIndexDetailsRoute extends Route {
    @service store;

    queryParams = {
        view: { refreshModel: false },
    };

    model({ public_id }) {
        return this.store.findRecord('place', public_id);
    }
}
