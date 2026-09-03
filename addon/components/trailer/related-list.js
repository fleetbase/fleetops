import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { task } from 'ember-concurrency';
import { action } from '@ember/object';
export default class TrailerRelatedListComponent extends Component {
    @service store;
    @service notifications;
    @service maintenanceScheduleActions;
    @service workOrderActions;
    @service maintenanceActions;
    @tracked records = [];
    constructor() {
        super(...arguments);
        this.load.perform();
    }
    get config() {
        return {
            schedules: { model: 'maintenance-schedule', params: { subject_type: 'fleet-ops:trailer', subject_uuid: this.args.resource.id }, service: this.maintenanceScheduleActions },
            'work-orders': { model: 'work-order', params: { target_type: 'trailer', target_uuid: this.args.resource.id }, service: this.workOrderActions },
            maintenance: { model: 'maintenance', params: { maintainable_type: 'trailer', maintainable_uuid: this.args.resource.id }, service: this.maintenanceActions },
        }[this.args.kind];
    }
    @task *load() {
        try {
            this.records = yield this.store.query(this.config.model, { ...this.config.params, sort: '-created_at' });
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
    @action view(record) {
        return this.config.service.transition.view(record);
    }
}
