import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class ConnectivityTelematicsDetailsSensorsRoute extends Route {
    @service store;

    model() {
        const telematic = this.modelFor('connectivity.telematics.details');
        return this.store.query('sensor', {
            telematic_uuid: telematic.id,
            sort: '-updated_at',
        });
    }
}
