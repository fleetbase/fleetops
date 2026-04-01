import Route from '@ember/routing/route';

export default class MaintenanceSchedulesIndexDetailsWorkOrdersRoute extends Route {
    model() {
        const schedule = this.modelFor('maintenance.schedules.index.details');
        return this.store.query('work-order', { schedule_uuid: schedule.id });
    }
}
