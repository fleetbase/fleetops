import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import moment from 'moment';

export default class WidgetTransactionsComponent extends Component {
    @service store;
    @tracked recentTransactions = [];
    @tracked isLoading = true;

    @action async getRecentTransactions() {
        const start = moment().startOf('week').toString();
        const end = moment().endOf('week').toString();

        this.recentTransactions = await this.fetchTransactions(start, end);
    }

    fetchTransactions(after, before) {
        this.isLoading = true;

        return new Promise((resolve) => {
            this.store.query('transaction', { limit: 12, after, before }).then((recentTransactions) => {
                this.isLoading = false;
                resolve(recentTransactions);
            });
        });
    }
}
