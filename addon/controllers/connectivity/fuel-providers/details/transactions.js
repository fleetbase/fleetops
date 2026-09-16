import FuelTransactionsController from '../../../management/fuel-transactions/index';
import { action } from '@ember/object';

export default class FuelIntegrationTransactionsController extends FuelTransactionsController {
    queryParams = ['page', 'limit', 'sort', 'query', 'sync_status', 'vehicle', 'transaction_at'];
    get columns() {
        return super.columns.filter((column) => column.valuePath !== 'provider');
    }

    @action refresh() {
        this.target.send('refreshFuelTransactions');
    }
}
