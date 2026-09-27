import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class AnalyticsReportsIndexNewRoute extends Route {
    @service notifications;
    @service hostRouter;
    @service abilities;
    @service intl;

    beforeModel() {
        if (this.abilities.cannot('iam create report')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('console.fleet-ops.analytics.reports.index');
        }
    }
}
