import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
export default class ManagementFleetsIndexEditRoute extends Route {
    @service store;

    model({ public_id }) {
        return this.store.findRecord('fleet', public_id);
    }
}
