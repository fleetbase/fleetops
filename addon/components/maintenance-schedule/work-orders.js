import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

/**
 * The work orders a maintenance schedule has raised, for the schedule's
 * context panel. The details route loads the same list in its own route.
 */
export default class MaintenanceScheduleWorkOrdersComponent extends Component {
    @service store;
    @service workOrderActions;
    @tracked workOrders = [];

    constructor() {
        super(...arguments);
        this.load.perform();
    }

    get schedule() {
        return this.args.resource ?? this.args.model;
    }

    @task *load() {
        if (!this.schedule?.id) {
            return;
        }

        const workOrders = yield this.store.query('work-order', { schedule_uuid: this.schedule.id });
        this.workOrders = workOrders?.toArray?.() ?? [...(workOrders ?? [])];
    }

    @action view(workOrder) {
        return this.workOrderActions.panel.view(workOrder);
    }
}
