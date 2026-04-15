import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class MaintenanceSchedulesIndexDetailsWorkOrdersRoute extends Route {
    @service store;

    model() {
        const schedule = this.modelFor('maintenance.schedules.index.details');
        return this.store.query('work-order', { schedule_uuid: schedule.id });
    }
}
