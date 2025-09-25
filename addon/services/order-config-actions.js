import ResourceActionService from '@fleetbase/ember-core/services/resource-action';
import { tracked } from '@glimmer/tracking';
import { debug } from '@ember/debug';
import { task } from 'ember-concurrency';

export default class OrderConfigActionsService extends ResourceActionService {
    @tracked allOrderConfigs = [];

    @task *loadAll() {
        try {
            const orderConfigs = yield this.store.findAll('order-config');
            this.allOrderConfigs = orderConfigs;

            return orderConfigs;
        } catch (err) {
            debug('Unable to load order configs: ' + err.message);
        }
    }
}
