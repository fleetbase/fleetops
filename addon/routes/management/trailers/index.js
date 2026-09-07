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
        serial_number: { refreshModel: true },
        vendor: { refreshModel: true },
        ownership_type: { refreshModel: true },
        refrigerated: { refreshModel: true },
        last_online_at: { refreshModel: true },
        created_at: { refreshModel: true },
        updated_at: { refreshModel: true },
    };

    model(params) {
        // Only forward meaningful filters; blank query params would otherwise be sent as
        // empty strings and coerced into no-op or mismatching backend filters.
        const query = Object.fromEntries(Object.entries(params).filter(([, value]) => value !== undefined && value !== null && value !== ''));

        return this.store.query('trailer', query);
    }
}
