import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class ManagementTrailersIndexRoute extends Route {
    @service store;
    queryParams = {
        page: { refreshModel: true },
        limit: { refreshModel: true },
        sort: { refreshModel: true },
        query: { refreshModel: true },
        public_id: { refreshModel: true },
        name: { refreshModel: true },
        code: { refreshModel: true },
        trailer_type: { refreshModel: true },
        status: { refreshModel: true },
        attachment_state: { refreshModel: true },
        vehicle: { refreshModel: true },
        connectivity_status: { refreshModel: true },
        trailer_make: { refreshModel: true },
        trailer_model: { refreshModel: true },
        trailer_year: { refreshModel: true },
        plate_number: { refreshModel: true },
        vin: { refreshModel: true },
        vendor: { refreshModel: true },
        serial_number: { refreshModel: true },
        length: { refreshModel: true },
        axle_count: { refreshModel: true },
        gvwr: { refreshModel: true },
        payload_capacity: { refreshModel: true },
        ownership_type: { refreshModel: true },
        devices_count: { refreshModel: true },
        equipment_count: { refreshModel: true },
        last_online_at: { refreshModel: true },
        created_at: { refreshModel: true },
        updated_at: { refreshModel: true },
    };
    model(params) {
        return this.store.query('trailer', { ...params });
    }
}
