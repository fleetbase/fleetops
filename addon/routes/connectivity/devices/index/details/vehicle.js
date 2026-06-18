import Route from '@ember/routing/route';

export default class ConnectivityDevicesIndexDetailsVehicleRoute extends Route {
    model() {
        return this.modelFor('connectivity.devices.index.details');
    }
}
