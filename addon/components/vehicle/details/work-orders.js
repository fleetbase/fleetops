import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';

export default class VehicleDetailsWorkOrdersComponent extends Component {
    @service workOrderActions;
    @service notifications;
    @service store;
    @tracked workOrders = [];

    get resourceId() {
        return this.args.resource.id ?? this.args.vehicle.id;
    }

    constructor() {
        super(...arguments);
        this.loadWorkOrders.perform();
    }

    @task *loadWorkOrders() {
        try {
            this.workOrders = yield this.store.query('work-order', {
                target_uuid: this.resourceId,
                target_type: 'vehicle',
                sort: '-created_at',
            });
        } catch (err) {
            this.notifications.serverError(err);
        }
    }
}
