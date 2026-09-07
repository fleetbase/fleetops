import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class TrailerDetailsWorkOrdersComponent extends Component {
    @service workOrderActions;
    @service notifications;
    @service store;
    @service trailerActions;
    @tracked workOrders = [];

    get trailer() {
        return this.args.resource;
    }

    constructor() {
        super(...arguments);
        this.loadWorkOrders.perform();
    }

    @task *loadWorkOrders() {
        try {
            const workOrders = yield this.store.query('work-order', {
                target_uuid: this.trailer.id,
                target_type: 'trailer',
                sort: '-created_at',
            });

            this.workOrders = Array.from(workOrders ?? []);
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action createWorkOrder() {
        return this.trailerActions.createWorkOrder(this.trailer, {}, { refresh: false, callback: () => this.loadWorkOrders.perform() });
    }
}
