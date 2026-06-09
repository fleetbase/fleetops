import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class ManagementFuelTransactionsIndexController extends Controller {
    @service tableContext;
    @service fetch;
    @service notifications;
    @service hostRouter;

    @tracked queryParams = ['page', 'limit', 'sort', 'query', 'provider', 'sync_status', 'vehicle', 'connection', 'transaction_at'];
    @tracked page = 1;
    @tracked limit;
    @tracked sort = '-transaction_at';
    @tracked query;
    @tracked provider;
    @tracked sync_status;
    @tracked vehicle;
    @tracked connection;
    @tracked transaction_at;
    @tracked table;

    get hasRecords() {
        return Array.from(this.model ?? []).length > 0;
    }

    get emptyStateTitle() {
        if (this.sync_status === 'unmatched') {
            return 'No unmatched fuel transactions';
        }

        if (this.connection) {
            return 'No transactions imported for this integration';
        }

        return 'No fuel transactions imported yet';
    }

    get emptyStateMessage() {
        if (this.sync_status === 'unmatched') {
            return 'FleetOps did not find imported provider bills that still need vehicle or trip matching.';
        }

        if (this.connection) {
            return 'Run a sync from the fuel integration detail page to import provider bills into this ledger.';
        }

        return 'Connect PetroApp or another fuel integration, then run a transaction sync. Imported provider bills appear here before linked Fuel Reports are created.';
    }

    get actionButtons() {
        return [
            {
                icon: 'refresh',
                onClick: this.refresh,
                helpText: 'Refresh',
            },
            {
                icon: 'gas-pump',
                text: 'Fuel Integrations',
                onClick: () => this.hostRouter.transitionTo('console.fleet-ops.connectivity.fuel-providers.index'),
            },
        ];
    }

    @action refresh() {
        this.target.send('refresh');
    }

    get bulkActions() {
        const selected = this.tableContext.getSelectedRows();

        return [
            {
                label: `Reprocess ${selected.length} selected`,
                fn: () => selected.forEach((transaction) => this.reprocessTransaction.perform(transaction)),
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
                filterOptions: ['imported', 'matched', 'unmatched', 'reviewed', 'ignored', 'duplicate', 'error'],
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
                cellComponent: 'table/cell/anchor',
                action: this.openFuelReport,
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
            {
                label: '',
                cellComponent: 'table/cell/dropdown',
                ddButtonText: false,
                ddButtonIcon: 'ellipsis-h',
                ddButtonIconPrefix: 'fas',
                ddMenuLabel: 'Fuel Transaction Actions',
                cellClassNames: 'overflow-visible',
                wrapperClass: 'flex items-center justify-end mx-2',
                sticky: 'right',
                width: 60,
                actions: [
                    { label: 'Review Details', fn: this.openDetails },
                    { label: 'Open Fuel Report', fn: this.openFuelReport },
                    { separator: true },
                    { label: 'Match to Vehicle', fn: this.matchVehicle },
                    { label: 'Match to Order', fn: this.matchOrder },
                    { label: 'Reprocess / Rematch', fn: (transaction) => this.reprocessTransaction.perform(transaction) },
                    { label: 'Ignore Transaction', fn: (transaction) => this.markReviewed.perform(transaction, 'ignored') },
                    { label: 'Mark Reviewed', fn: (transaction) => this.markReviewed.perform(transaction, 'reviewed') },
                ],
                sortable: false,
                filterable: false,
                resizable: false,
                searchable: false,
            },
        ];
    }

    @action openDetails(transaction) {
        return this.hostRouter.transitionTo('console.fleet-ops.management.fuel-transactions.index.details', transaction);
    }

    @action openFuelReport(transaction) {
        if (!transaction?.fuel_report_id) {
            this.notifications.info('This transaction does not have a linked Fuel Report yet.');
            return;
        }

        return this.hostRouter.transitionTo('console.fleet-ops.management.fuel-reports.index.details', transaction.fuel_report_id);
    }

    @action matchVehicle(transaction) {
        return this.hostRouter.transitionTo('console.fleet-ops.management.fuel-transactions.index.details', transaction);
    }

    @action matchOrder(transaction) {
        return this.hostRouter.transitionTo('console.fleet-ops.management.fuel-transactions.index.details', transaction);
    }

    @task *reprocessTransaction(transaction) {
        try {
            yield this.fetch.post(`fuel-provider-transactions/${transaction.id}/reprocess`);
            this.notifications.success('Fuel transaction reprocessed.');
            this.target.send('refresh');
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task *markReviewed(transaction, status) {
        try {
            yield this.fetch.post(`fuel-provider-transactions/${transaction.id}/review`, { status });
            this.notifications.success(status === 'ignored' ? 'Fuel transaction ignored.' : 'Fuel transaction marked reviewed.');
            this.target.send('refresh');
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
}
