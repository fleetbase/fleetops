import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';

export default class AnalyticsReportsIndexDetailsController extends Controller {
    @service hostRouter;
    @tracked tabs = [
        {
            route: 'analytics.reports.index.details.index',
            label: 'Overview',
        },
        {
            route: 'analytics.reports.index.details.result',
            label: 'Result',
            icon: 'table',
        },
    ];
    @tracked actionButtons = [
        {
            icon: 'pencil',
            fn: () => this.hostRouter.transitionTo('console.fleet-ops.analytics.reports.index.edit', this.model),
        },
    ];
}
