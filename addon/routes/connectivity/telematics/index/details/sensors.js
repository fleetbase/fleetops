import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class ConnectivityTelematicsIndexDetailsSensorsRoute extends Route {
    @service store;

    model() {
        const telematic = this.modelFor('connectivity.telematics.index.details');
        return this.store.query('sensor', {
            telematic_uuid: telematic.id,
            sort: '-updated_at',
        });
    }
}
