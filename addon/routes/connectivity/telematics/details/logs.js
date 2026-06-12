import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class ConnectivityTelematicsDetailsLogsRoute extends Route {
    @service fetch;

    model() {
        const telematic = this.modelFor('connectivity.telematics.details');

        return this.fetch.get(`telematics/${telematic.id}/logs`);
    }

    setupController(controller, model) {
        super.setupController(controller, model);
        controller.telematic = this.modelFor('connectivity.telematics.details');
    }
}
