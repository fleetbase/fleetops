import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';

export default class VehicleDetailsMaintenanceHistoryComponent extends Component {
    @service maintenanceActions;
    @service notifications;
    @service store;
    @tracked maintenanceHistory = [];

    get resourceId() {
        return this.args.resource.id ?? this.args.vehicle.id;
    }

    constructor() {
        super(...arguments);
        this.loadMaintenanceHistory.perform();
    }

    @task *loadMaintenanceHistory() {
        try {
            this.maintenanceHistory = yield this.store.query('maintenance', {
                maintainable_uuid: this.resourceId,
                maintainable_type: 'vehicle',
                sort: '-created_at',
            });
        } catch (err) {
            this.notifications.serverError(err);
        }
    }
}
