import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';

/**
 * A vehicle's inspection history, newest first.
 *
 * The same submissions the Inspections screen lists, narrowed to this truck:
 * what was filed against it, what failed, and a way into the record. Filing an
 * inspection is a driver's job — through the app or a public link — so nothing
 * is created from here.
 */
export default class VehicleDetailsInspectionsComponent extends Component {
    @service inspectionSubmissionActions;
    @service notifications;
    @service store;
    @tracked inspections = [];

    get vehicle() {
        return this.args.resource ?? this.args.vehicle;
    }

    constructor() {
        super(...arguments);
        this.loadInspections.perform();
    }

    @task *loadInspections() {
        try {
            // `vehicle` is neither a column nor searchable on this model, so it
            // is dropped silently; `vehicle_uuid` is fillable and is what the
            // index filter actually matches on.
            const inspections = yield this.store.query('inspection-submission', {
                vehicle_uuid: this.vehicle.id,
                sort: '-created_at',
            });

            this.inspections = Array.from(inspections ?? []);
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
}
