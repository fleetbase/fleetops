import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class VehicleDetailsSchedulesComponent extends Component {
    @service maintenanceScheduleActions;
    @service notifications;
    @service store;
    @service vehicleActions;
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
                subject_type: 'fleet-ops:vehicle',
                sort: '-created_at',
            });
        } catch (err) {
            this.notifications.serverError(err);
        }
    }

    @action createSchedule() {
        return this.vehicleActions.scheduleMaintenance(this.args.vehicle, {}, { refresh: false, callback: () => this.loadSchedules.perform() });
    }
}
