import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { debug } from '@ember/debug';
import { task } from 'ember-concurrency';

export default class MapToolbarComponent extends Component {
    @service leafletMapManager;
    @service orderListOverlay;
    @service mapDrawer;
    @service globalSearch;
    @service fetch;
    @tracked activeOrderCount = 0;

    constructor() {
        super(...arguments);
        this.getActiveOrderCount.perform();
    }

    @action calculatePosition(trigger) {
        let { width } = trigger.getBoundingClientRect();

        let style = {
            marginTop: '0px',
            left: `${width + 13}px`,
            top: '0px',
        };

        return { style };
    }

    @task *getActiveOrderCount() {
        try {
            const count = yield this.fetch.get('fleet-ops/metrics', { discover: ['orders_in_progress'] });
            this.activeOrderCount = count.orders_in_progress;
            return count;
        } catch (err) {
            debug('Failed to get active order count: ' + err.message);
        }
    }
}
