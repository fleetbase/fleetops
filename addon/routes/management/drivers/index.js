import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class ManagementDriversIndexRoute extends Route {
    @service store;
    @service intl;

    queryParams = {
        page: { refreshModel: true },
        limit: { refreshModel: true },
        sort: { refreshModel: true },
        query: { refreshModel: true },
        status: { refreshModel: true },
        name: { refreshModel: true },
        public_id: { refreshModel: true },
        internal_id: { refreshModel: true },
        drivers_license_number: { refreshModel: true },
        phone: { refreshModel: true },
        country: { refreshModel: true },
        fleet: { refreshModel: true },
        vendor: { refreshModel: true },
        vehicle: { refreshModel: true },
        created_at: { refreshModel: true },
        updated_at: { refreshModel: true },
        'within[latitude]': { refreshModel: true, replace: true },
        'within[longitude]': { refreshModel: true, replace: true },
        'within[radius]': { refreshModel: true, replace: true },
        'within[where]': { refreshModel: true, replace: true },
    };

    beforeModel() {
        this.intl.setLocale(['en-us']);
    }

    model(params) {
        return this.store.query('driver', { ...params });
    }
}
