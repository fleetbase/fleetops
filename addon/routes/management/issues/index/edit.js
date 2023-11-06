import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class ManagementIssuesIndexEditRoute extends Route {
    @service store;

    model({ public_id }) {
        return this.store.queryRecord('issue', { public_id, single: true, with: ['driver', 'vehicle', 'assignee', 'reporter'] });
    }
}
