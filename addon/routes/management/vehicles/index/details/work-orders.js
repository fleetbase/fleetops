import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class ManagementVehiclesIndexDetailsWorkOrdersRoute extends Route {
    @service store;

    model() {
        const vehicle = this.modelFor('management.vehicles.index.details');
        return this.store.query('work-order', {
            target_uuid: vehicle.id,
            target_type: 'vehicle',
            sort: '-created_at',
        });
    }
}
