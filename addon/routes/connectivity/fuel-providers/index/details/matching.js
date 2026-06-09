import Route from '@ember/routing/route';

export default class ConnectivityFuelProvidersIndexDetailsMatchingRoute extends Route {
    model() {
        return this.modelFor('connectivity.fuel-providers.index.details');
    }
}
