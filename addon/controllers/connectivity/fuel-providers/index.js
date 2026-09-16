import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class ConnectivityFuelProvidersIndexController extends Controller {
    @service fetch;
    @service notifications;
    @service tableContext;
    @service fuelIntegrationActions;
    @service intl;
    @service modalsManager;

    @tracked queryParams = ['page', 'limit', 'sort', 'query', 'provider', 'status', 'environment'];
    @tracked page = 1;
    @tracked limit;
    @tracked sort = '-updated_at';
    @tracked query;
    @tracked provider;
    @tracked status;
    @tracked environment;
    @tracked table;
    @tracked providers = [];

    constructor() {
        super(...arguments);
        this.loadProviders.perform();
    }

    get actionButtons() {
        return [
            {
                icon: 'refresh',
                onClick: this.refresh,
                helpText: this.intl.t('common.reload'),
            },
            {
                icon: 'plus',
                text: 'Connect Integration',
                type: 'primary',
                onClick: () => this.fuelIntegrationActions.transition.create(),
            },
        ];
    }

    get bulkActions() {
        const selected = this.tableContext.getSelectedRows();

        return [
            {
                label: `Sync ${selected.length} selected`,
                fn: () => selected.forEach((connection) => this.syncConnection(connection)),
            },
        ];
    }

    get columns() {
        return [
            {
                sticky: true,
                label: 'Integration',
                valuePath: 'displayName',
                sortParam: 'name',
                cellComponent: 'table/cell/anchor',
                action: this.openConnection,
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'query',
                filterComponent: 'filter/string',
            },
            {
                label: 'Provider Key',
                valuePath: 'provider',
                resizable: true,
                sortable: true,
                filterable: true,
                filterComponent: 'filter/string',
            },
            {
                label: 'Status',
                valuePath: 'status',
                cellComponent: 'table/cell/status',
                resizable: true,
                sortable: true,
                filterable: true,
                filterComponent: 'filter/multi-option',
                filterOptions: ['configured', 'connected', 'active', 'error', 'disabled'],
            },
            {
                label: 'Last Sync',
                valuePath: 'lastSyncedAt',
                sortParam: 'last_synced_at',
                resizable: true,
                sortable: true,
            },
            {
                label: 'New in last sync',
                valuePath: 'lastImported',
                resizable: true,
                sortable: false,
            },
            {
                label: 'Unmatched in last sync',
                valuePath: 'lastUnmatched',
                resizable: true,
                sortable: false,
            },
            {
                label: 'Last Error',
                valuePath: 'last_error',
                resizable: true,
                hidden: true,
            },
            {
                label: '',
                cellComponent: 'table/cell/dropdown',
                ddButtonText: false,
                ddButtonIcon: 'ellipsis-h',
                ddButtonIconPrefix: 'fas',
                ddMenuLabel: 'Fuel Integration Actions',
                cellClassNames: 'overflow-visible',
                wrapperClass: 'flex items-center justify-end mx-2',
                sticky: 'right',
                width: 60,
                actions: [
                    { label: 'Open Integration', fn: this.openConnection },
                    { label: 'Edit Settings', fn: this.editConnection },
                    { separator: true },
                    { label: 'Test Connection', fn: this.testConnection },
                    { label: 'Sync Transactions', fn: this.syncConnection },
                ],
                sortable: false,
                filterable: false,
                resizable: false,
                searchable: false,
            },
        ];
    }

    @task *loadProviders() {
        try {
            this.providers = yield this.fetch.get('fuel-provider-connections/providers');
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action refresh() {
        this.target.send('refreshFuelConnections');
    }

    @action openConnection(connection) {
        return this.fuelIntegrationActions.transition.view(connection);
    }

    @action editConnection(connection) {
        return this.fuelIntegrationActions.transition.edit(connection);
    }

    @action testConnection(connection) {
        return this.modalsManager.show('modals/fuel-connection-diagnostics', {
            title: 'Test Fuel Connection',
            acceptButtonText: 'Run Test',
            acceptButtonIcon: 'plug',
            declineButtonText: 'Close',
            connection,
            onTested: () => connection.reload(),
        });
    }

    @action async syncConnection(connection) {
        try {
            await this.fetch.post(`fuel-provider-connections/${connection.id}/sync`, { async: true });
            this.notifications.success('Fuel transaction sync queued.');
            await connection.reload();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
}
