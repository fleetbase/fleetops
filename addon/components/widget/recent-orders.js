import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import moment from 'moment';

export default class WidgetRecentOrdersComponent extends Component {
    @service store;
    @tracked recentOrders = [];
    @tracked isLoading = false;
    @action async getRecentOrders() {
        const start = moment().startOf('week').toString();
        const end = moment().endOf('week').toString();

        this.isLoading = true;
        const recentOrders = await this.store.query('order', {
            limit: 12,
            after: start,
            before: end,
        });
        this.isLoading = false;
        this.recentOrders = recentOrders;
    }
}
