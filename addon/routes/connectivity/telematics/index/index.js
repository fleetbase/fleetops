import Route from '@ember/routing/route';

export default class ConnectivityTelematicsIndexIndexRoute extends Route {
    model() {
        return this.modelFor('connectivity.telematics.index');
    }
}
