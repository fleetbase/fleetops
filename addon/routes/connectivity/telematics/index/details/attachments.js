import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class ConnectivityTelematicsIndexDetailsAttachmentsRoute extends Route {
    @service store;

    model() {
        const telematic = this.modelFor('connectivity.telematics.index.details');

        return this.store.query('device', {
            telematic_uuid: telematic.id,
        });
    }
}
