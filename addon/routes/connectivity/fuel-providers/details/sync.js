import Route from '@ember/routing/route';

export default class FuelIntegrationSyncRoute extends Route {
    setupController(controller, model) {
        super.setupController(controller, model);
        controller.resetDates();
    }
    model() {
        return this.modelFor('connectivity.fuel-providers.details');
    }
}
