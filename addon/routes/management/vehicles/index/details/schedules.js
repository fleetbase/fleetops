import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class ManagementVehiclesIndexDetailsSchedulesRoute extends Route {
    @service store;

    model() {
        const vehicle = this.modelFor('management.vehicles.index.details');
        return this.store.query('maintenance-schedule', {
            subject_uuid: vehicle.id,
            subject_type: 'vehicle',
            sort: '-created_at',
        });
    }
}
