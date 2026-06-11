import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class ConnectivityFuelProvidersIndexDetailsTransactionsController extends Controller {
    @service hostRouter;

    get columns() {
        return [
            { sticky: true, label: 'Transaction', valuePath: 'provider_transaction_id', cellComponent: 'click-to-copy', resizable: true },
            { label: 'Status', valuePath: 'sync_status', cellComponent: 'table/cell/status', resizable: true },
            { label: 'Vehicle', valuePath: 'vehicle_name', resizable: true },
            { label: 'Station', valuePath: 'station_name', resizable: true },
            { label: 'Liters', valuePath: 'volume', resizable: true },
            { label: 'Amount', valuePath: 'amount', cellComponent: 'table/cell/currency', resizable: true },
            { label: 'Fuel Report', valuePath: 'fuel_report_id', cellComponent: 'click-to-copy', resizable: true },
            { label: 'Date', valuePath: 'transaction_at', resizable: true },
        ];
    }

    @action refresh() {
        return this.hostRouter.refresh();
    }
}
