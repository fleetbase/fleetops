import Route from '@ember/routing/route';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class OperationsOrdersIndexRoute extends Route {
    @service store;
    @service leafletMapManager;

    @tracked queryParams = {
        page: { refreshModel: true },
        limit: { refreshModel: true },
        sort: { refreshModel: true },
        query: { refreshModel: true },
        status: { refreshModel: true },
        public_id: { refreshModel: true },
        internal_id: { refreshModel: true },
        payload: { refreshModel: true },
        tracking: { refreshModel: true },
        facilitator: { refreshModel: true },
        driver: { refreshModel: true },
        vehicle: { refreshModel: true },
        customer: { refreshModel: true },
        pickup: { refreshModel: true },
        dropoff: { refreshModel: true },
        created_at: { refreshModel: true },
        updated_at: { refreshModel: true },
        scheduled_at: { refreshModel: true },
        without_driver: { refreshModel: true },
        bulk_query: { refreshModel: true },
        after: { refreshModel: true },
        before: { refreshModel: true },
        type: { refreshModel: true },
        layout: { refreshModel: false },
        drawerOpen: { refreshModel: false },
        drawerTab: { refreshModel: false },
        orderPanelOpen: { refreshModel: false },
    };

    // @action willTransition(transition) {
    //     const NS = 'operations.orders';
    //     const toName = transition.to?.name ?? '';
    //     const fromName = transition.from?.name ?? '';
    //     const wasInNS = fromName === NS || fromName.startsWith(`${NS}.`);
    //     const goingOut = !(toName === NS || toName.startsWith(`${NS}.`));

    //     if (wasInNS && goingOut) {
    //         this.leafletMapManager.livemap('hideAll');
    //     }

    //     return true;
    // }

    model(params) {
        return this.store.query('order', params);
    }
}
