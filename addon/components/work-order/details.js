import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { debug } from '@ember/debug';
import { task } from 'ember-concurrency';

/**
 * A work order raised from a failed inspection is named on that inspection,
 * and the panel read none of it — the inspection that caused a repair was
 * invisible from the repair. It is looked up rather than read from meta, so
 * one attached by hand shows up too.
 */
export default class WorkOrderDetailsComponent extends Component {
    @service store;
    @service inspectionSubmissionActions;
    @tracked inspections = [];

    constructor() {
        super(...arguments);
        this.loadInspections.perform();
    }

    @task *loadInspections() {
        const id = this.args.resource?.uuid ?? this.args.resource?.id;
        if (!id) {
            return;
        }

        try {
            const inspections = yield this.store.query('inspection-submission', { work_order_uuid: id, sort: '-created_at' });
            this.inspections = Array.from(inspections ?? []);
        } catch (error) {
            // Supplementary to the work order: a failed lookup should not put an
            // error in front of someone who opened the work order to read it.
            debug(`Could not load the inspections linked to work order ${id}: ${error.message}`);
        }
    }

    @action viewInspection(submission) {
        return this.inspectionSubmissionActions.transition.view(submission);
    }
}
