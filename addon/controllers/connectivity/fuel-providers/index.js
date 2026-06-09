import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class ConnectivityFuelProvidersIndexController extends Controller {
    @service fetch;
    @service notifications;
    @service tableContext;

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
                helpText: 'Refresh',
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
                label: 'Provider',
                valuePath: 'displayName',
                cellComponent: 'click-to-copy',
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'query',
                filterComponent: 'filter/string',
            },
            {
                label: 'Key',
                valuePath: 'provider',
                resizable: true,
                sortable: true,
                filterable: true,
                filterComponent: 'filter/string',
            },
            {
                label: 'Environment',
                valuePath: 'environment',
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
                resizable: true,
                sortable: true,
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
                ddMenuLabel: 'Fuel Provider Actions',
                cellClassNames: 'overflow-visible',
                wrapperClass: 'flex items-center justify-end mx-2',
                sticky: 'right',
                width: 60,
                actions: [
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
        this.target.send('refresh');
    }

    @action async testConnection(connection) {
        try {
            await this.fetch.post(`fuel-provider-connections/${connection.id}/test-connection`);
            this.notifications.success('Fuel provider connection tested.');
            this.refresh();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action async syncConnection(connection) {
        try {
            await this.fetch.post(`fuel-provider-connections/${connection.id}/sync`, { async: true });
            this.notifications.success('Fuel provider sync queued.');
            this.refresh();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
}
