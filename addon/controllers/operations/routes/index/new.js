import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class OperationsRoutesIndexNewController extends Controller {
    @service store;
    @tracked panel;
    @tracked selectedOrders = '';
    @tracked waypoints = [];

    @action setOverlayPanelContext(overlayPanelContext) {
        this.panel = overlayPanelContext;
        this.loadSelectedOrders.perform(this.selectedOrders.split(','));
    }

    @task *loadSelectedOrders(selectedOrders) {
        const orders = yield this.store.query('order', { only: selectedOrders });
        this.extractWaypoints(orders);
    }

    extractWaypoints(orders = []) {
        const extracted = [];

        orders.forEach((order) => {
            const pickup = order.get('payload.pickup');
            const dropoff = order.get('payload.dropoff');
            const waypoints = order.get('payload.waypoints')?.toArray() ?? [];
            extracted.push(pickup, dropoff, ...waypoints);
        });

        this.waypoints = extracted.filter(Boolean);
    }
}
