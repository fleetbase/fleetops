import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class ManagementIssuesIndexEditRoute extends Route {
    @service store;

    model({ public_id }) {
        return this.store.findRecord('issue', public_id);
    }
}
