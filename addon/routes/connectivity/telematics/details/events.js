import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class ConnectivityTelematicsDetailsEventsRoute extends Route {
    @service store;

    model() {
        const telematic = this.modelFor('connectivity.telematics.details');
        return this.store.query('device-event', {
            telematic: telematic.id,
            sort: '-created_at',
        });
    }
}
