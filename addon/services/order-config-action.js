import ResourceActionService from '@fleetbase/ember-core/services/resource-action';
import { debug } from '@ember/debug';
import { task } from 'ember-concurrency';

export default class OrderConfigActionService extends ResourceActionService {
    @task *loadAll() {
        try {
            const orderConfigs = yield this.store.findAll('order-config');
            return orderConfigs;
        } catch (err) {
            debug('Unable to load order configs: ' + err.message);
        }
    }
}
