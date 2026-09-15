import Route from '@ember/routing/route';

export default class MaintenanceInspectionSubmissionsIndexDetailsAuditRoute extends Route {
    /** The tab renders the submission the record panel is showing. */
    model() {
        return this.modelFor('maintenance.inspection-submissions.index.details');
    }
}
