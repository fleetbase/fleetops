import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class ManagementPlacesIndexEditRoute extends Route {
    @service store;

    model({ public_id }) {
        return this.store.findRecord('place', public_id);
    }
}
