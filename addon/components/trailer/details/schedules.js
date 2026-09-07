import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class TrailerDetailsSchedulesComponent extends Component {
    @service maintenanceScheduleActions;
    @service notifications;
    @service store;
    @service trailerActions;
    @tracked schedules = [];

    get trailer() {
        return this.args.resource;
    }

    constructor() {
        super(...arguments);
        this.loadSchedules.perform();
    }

    @task *loadSchedules() {
        try {
            const schedules = yield this.store.query('maintenance-schedule', {
                subject_uuid: this.trailer.id,
                subject_type: 'fleet-ops:trailer',
                sort: '-created_at',
            });

            this.schedules = Array.from(schedules ?? []);
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action createSchedule() {
        return this.trailerActions.scheduleMaintenance(this.trailer, {}, { refresh: false, callback: () => this.loadSchedules.perform() });
    }
}
