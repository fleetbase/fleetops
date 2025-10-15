import Service, { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { debug } from '@ember/debug';
import { task } from 'ember-concurrency';

export default class ServiceAreaManagerService extends Service {
    @service serviceAreaActions;
    @service zoneActions;
    @service store;
    @tracked serviceAreas = [];

    @task *loadAll() {
        try {
            const serviceAreas = yield this.store.findAll('service-area');
            this.serviceAreas = serviceAreas;
            return serviceAreas;
        } catch (err) {
            debug('Unable to load service areas: ' + err.message);
        }
    }

    @action drawServiceArea() {}
}
