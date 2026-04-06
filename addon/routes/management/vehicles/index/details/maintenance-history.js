import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class ManagementVehiclesIndexDetailsMaintenanceHistoryRoute extends Route {
    @service store;

    model() {
        const vehicle = this.modelFor('management.vehicles.index.details');
        return this.store.query('maintenance', {
            maintainable_uuid: vehicle.id,
            maintainable_type: 'vehicle',
            sort: '-created_at',
        });
    }
}
