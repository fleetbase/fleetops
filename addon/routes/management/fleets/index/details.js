import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
export default class ManagementFleetsIndexDetailsRoute extends Route {
    @service store;

    model({ public_id }) {
        return this.store.queryRecord('fleet', { public_id, single: true, with: ['parent_fleet', 'service_area', 'zone'] });
    }
}
