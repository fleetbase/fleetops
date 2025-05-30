import Service, { inject as service } from '@ember/service';

export default class RouteOptimizationInterfaceService extends Service {
    @service fetch;

    optimize() {
        throw new Error(`${this.constructor.name} must implement optimize(params, options)`);
    }
}
