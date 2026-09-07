import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class ManagementTrailersIndexDetailsRoute extends Route {
    @service store;
    @service notifications;
    @service hostRouter;
    @service abilities;
    @service intl;

    @action error(error) {
        this.notifications.serverError(error);
        if (typeof error?.message === 'string' && error.message.endsWith('not found')) {
            return this.hostRouter.transitionTo('console.fleet-ops.management.trailers.index');
        }
    }

    beforeModel() {
        if (this.abilities.cannot('fleet-ops view trailer')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('console.fleet-ops.management.trailers.index');
        }
    }

    model({ public_id }) {
        // The details panel needs the towing state, so load the connection eagerly.
        return this.store.queryRecord('trailer', { public_id, single: true, with: ['currentConnection.vehicle', 'category', 'vendor', 'warranty'] });
    }
}
