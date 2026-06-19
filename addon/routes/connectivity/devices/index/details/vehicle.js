import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class ConnectivityDevicesIndexDetailsVehicleRoute extends Route {
    @service store;

    async model() {
        const device = this.modelFor('connectivity.devices.index.details');
        const vehicle = await Promise.resolve(device?.attachable);

        if (!vehicle?.id) {
            return { device, positions: [] };
        }

        let positions = [];

        try {
            positions = await this.store.query('position', {
                subject_uuid: vehicle.id,
                sort: '-created_at',
                limit: 5,
            });
        } catch (_) {
            positions = [];
        }

        return { device, positions };
    }
}
