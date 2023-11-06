import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class ManagementFuelReportsIndexDetailsRoute extends Route {
    @service store;

    queryParams = {
        view: { refreshModel: false },
    };

    model({ public_id }) {
        return this.store.queryRecord('fuel-report', { public_id, single: true, with: ['driver', 'vehicle', 'reporter'] });
    }
}
