import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class OperationsDriverSchedulesIndexRoute extends Route {
    @service store;
    @service notifications;

    async model() {
        try {
            const drivers = await this.store.query('driver', {
                limit: 200,
                status: 'active',
            });
            return { drivers: drivers.toArray() };
        } catch (error) {
            this.notifications.serverError(error);
            return { drivers: [] };
        }
    }

    setupController(controller, model) {
        super.setupController(controller, model);
        controller.set('drivers', model.drivers);
        controller.loadScheduleItems.perform();
    }
}
