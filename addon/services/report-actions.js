import ReportActionsBaseService from '@fleetbase/ember-core/services/report-actions';

export default class ReportActionsService extends ReportActionsBaseService {
    defaultAttributes = { type: 'fleet-ops' };

    transition = {
        view: (report) => this.transitionTo('analytics.reports.index.details', report),
        edit: (report) => this.transitionTo('analytics.reports.index.edit', report),
        create: () => this.transitionTo('analytics.reports.index.new'),
    };
}
