import Route from '@ember/routing/route';

export default class OperationsZonesRoute extends Route {
    model() {
        return this.store.findAll('zone');
    }
}
