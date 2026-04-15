import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';

export default class VehicleDetailsSchedulesComponent extends Component {
    @service maintenanceScheduleActions;
    @service notifications;
    @service store;
    @tracked schedules = [];

    get resourceId() {
        return this.args.resource.id ?? this.args.vehicle.id;
    }

    constructor() {
        super(...arguments);
        this.loadSchedules.perform();
    }

    @task *loadSchedules() {
        try {
            this.schedules = yield this.store.query('maintenance-schedule', {
                subject_uuid: this.resourceId,
                subject_type: 'vehicle',
                sort: '-created_at',
            });
        } catch (err) {
            this.notifications.serverError(err);
        }
    }
}
