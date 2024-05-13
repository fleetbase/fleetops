import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { inject as controller } from '@ember/controller';
import { action, computed } from '@ember/object';
import { task } from 'ember-concurrency-decorators';
export default class CustomerOrderHistoryComponent extends Component {
    @service store;
    @service fetch;
    @service intl;
    @service appCache;
    @service modalsManager;
    @service contextPanel;
    @tracked isLoading = true;
    @tracked orders = [];
    @tracked driver;
    @controller('orders.index.view') orderDetailsController;

    @computed('args.title') get title() {
        return this.args.title ?? this.intl.t('fleetops.component.widget.orders.widget-title');
    }

    constructor() {
        super(...arguments);
        this.driver = this.args.driver;
        this.loadOrdersForDriver.perform();
    }

    @task *loadOrdersForDriver(params = {}) {
        try {
            this.orders = yield this.store.query('order', {
                driver_assigned_uuid: this.driver.id,
                ...params,
            });
        } catch (error) {
            this.notifications.serverError('error', error);
        }
    }

    @action search(event) {
        // this.reloadOrders.perform({ query: event.target.value ?? '' });
    }

    @action async viewOrder(order) {
        this.contextPanel.focus(order, 'viewing');
    }
}
