import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';

/**
 * What has been filed against this form, newest first.
 *
 * The mirror of the vehicle's Inspections tab: there you ask what happened to
 * one truck, here what a form has collected. Every row shares the form, so the
 * vehicle leads instead and the form name is left out entirely.
 */
export default class InspectionFormDetailsSubmissionsComponent extends Component {
    @service inspectionSubmissionActions;
    @service notifications;
    @service store;
    @tracked submissions = [];

    get form() {
        return this.args.resource ?? this.args.form;
    }

    constructor() {
        super(...arguments);
        this.loadSubmissions.perform();
    }

    @task *loadSubmissions() {
        try {
            // `inspection_form_uuid` is fillable, which is what makes the index
            // filter bind to it — the same route `vehicle_uuid` takes.
            const submissions = yield this.store.query('inspection-submission', {
                inspection_form_uuid: this.form.uuid ?? this.form.id,
                sort: '-created_at',
            });

            this.submissions = Array.from(submissions ?? []);
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
}
