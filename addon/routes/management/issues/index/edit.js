import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class ManagementIssuesIndexEditRoute extends Route {
    @service store;
    @service notifications;
    @service hostRouter;
    @service abilities;
    @service intl;

    beforeModel() {
        if (this.abilities.cannot('fleet-ops update issue')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('console.fleet-ops.issues.index');
        }
    }

    model({ public_id }) {
        return this.store.queryRecord('issue', { public_id, single: true, with: ['driver', 'vehicle', 'assignee', 'reporter'] });
    }
}
