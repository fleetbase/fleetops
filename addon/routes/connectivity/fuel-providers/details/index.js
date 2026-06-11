import Route from '@ember/routing/route';

export default class ConnectivityFuelProvidersIndexDetailsIndexRoute extends Route {
    model() {
        return this.modelFor('connectivity.fuel-providers.details');
    }
}
