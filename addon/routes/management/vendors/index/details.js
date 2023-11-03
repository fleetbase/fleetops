import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class ManagementVendorsIndexDetailsRoute extends Route {
    @service store;

    queryParams = {
        view: { refreshModel: false },
    };

    model({ public_id }) {
        const isIntegratedVendor = typeof public_id === 'string' && public_id.startsWith('integrated_vendor_');
        return this.store.findRecord(isIntegratedVendor ? 'integrated-vendor' : 'vendor', public_id);
    }
}
