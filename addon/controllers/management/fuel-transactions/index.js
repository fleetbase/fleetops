import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';

export default class ManagementFuelTransactionsIndexController extends Controller {
    @service tableContext;

    @tracked queryParams = ['page', 'limit', 'sort', 'query', 'provider', 'sync_status', 'vehicle', 'transaction_at'];
    @tracked page = 1;
    @tracked limit;
    @tracked sort = '-transaction_at';
    @tracked query;
    @tracked provider;
    @tracked sync_status;
    @tracked vehicle;
    @tracked transaction_at;
    @tracked table;

    get actionButtons() {
        return [
            {
                icon: 'refresh',
                onClick: () => this.target.send('refresh'),
                helpText: 'Refresh',
            },
        ];
    }

    get bulkActions() {
        const selected = this.tableContext.getSelectedRows();

        return [
            {
                label: `${selected.length} selected`,
                fn: () => {},
            },
        ];
    }

    get columns() {
        return [
            {
                sticky: true,
                label: 'Transaction',
                valuePath: 'provider_transaction_id',
                cellComponent: 'click-to-copy',
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'query',
                filterComponent: 'filter/string',
            },
            {
                label: 'Provider',
                valuePath: 'provider',
                resizable: true,
                sortable: true,
                filterable: true,
                filterComponent: 'filter/string',
            },
            {
                label: 'Status',
                valuePath: 'sync_status',
                cellComponent: 'table/cell/status',
                resizable: true,
                sortable: true,
                filterable: true,
                filterComponent: 'filter/multi-option',
                filterOptions: ['imported', 'matched', 'unmatched', 'duplicate', 'error'],
            },
            {
                label: 'Vehicle',
                valuePath: 'vehicle_name',
                resizable: true,
                sortable: false,
                filterable: true,
                filterParam: 'vehicle',
                filterComponent: 'filter/model',
                model: 'vehicle',
                modelNamePath: 'displayName',
            },
            {
                label: 'Card / Internal',
                valuePath: 'vehicle_card_id',
                resizable: true,
            },
            {
                label: 'Trip',
                valuePath: 'trip_number',
                resizable: true,
                hidden: true,
            },
            {
                label: 'Station',
                valuePath: 'station_name',
                resizable: true,
            },
            {
                label: 'Liters',
                valuePath: 'volume',
                resizable: true,
                sortable: true,
            },
            {
                label: 'Amount',
                valuePath: 'amount',
                cellComponent: 'table/cell/currency',
                resizable: true,
                sortable: true,
            },
            {
                label: 'Fuel Report',
                valuePath: 'fuel_report_id',
                cellComponent: 'click-to-copy',
                resizable: true,
            },
            {
                label: 'Date',
                valuePath: 'transactionAt',
                sortParam: 'transaction_at',
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'transaction_at',
                filterComponent: 'filter/date',
            },
        ];
    }
}
