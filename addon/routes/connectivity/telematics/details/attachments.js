import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class ConnectivityTelematicsDetailsAttachmentsRoute extends Route {
    @service store;

    model() {
        const telematic = this.modelFor('connectivity.telematics.details');

        return this.store.query('device', {
            telematic_uuid: telematic.id,
        });
    }
}
