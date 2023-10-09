import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class OperationsSchedulerIndexRoute extends Route {
    @service store;

    model() {
        return this.store.query('order', { status: 'created', with: ['payload'] });
    }
}
