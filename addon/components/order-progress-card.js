import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import registerComponent from '../utils/register-component';
import OrderProgressBarComponent from './order-progress-bar';

export default class OrderProgressCardComponent extends Component {
    @service fetch;
    @service notifications;
    @tracked order;
    @tracked trackerData = {};

    constructor(owner, { order }) {
        super(...arguments);
        registerComponent(owner, OrderProgressBarComponent);

        this.order = order;
        this.getTrackerData.perform();
    }

    @action handleClick() {
        if (typeof this.args.onClick === 'function') {
            this.args.onClick(this.order);
        }
    }

    @task *getTrackerData() {
        try {
            this.trackerData = yield this.fetch.get(`orders/${this.order.id}/tracker`);
            this.order.set('trackerData', this.trackerData);

            if (typeof this.args.onTrackerDataLoaded === 'function') {
                this.args.onTrackerDataLoaded(this.order);
            }
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
}
