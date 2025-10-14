import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { debug } from '@ember/debug';
import { task } from 'ember-concurrency';

export default class OrderDetailsTrackingComponent extends Component {
    @service orderActions;

    constructor() {
        super(...arguments);
        this.loadTrackerData.perform();
    }

    @task *loadTrackerData() {
        try {
            yield this.args.resource.loadTrackerData();
        } catch (err) {
            debug('Failed to load order tracker data: ' + err.message);
        }
    }
}
