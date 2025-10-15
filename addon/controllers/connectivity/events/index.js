import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';

export default class ConnectivityEventsIndexController extends Controller {
    @service deviceEventActions;
    @service intl;

    /** query params */
    @tracked queryParams = ['name', 'page', 'limit', 'sort', 'query', 'public_id', 'created_at', 'updated_at'];
    @tracked page = 1;
    @tracked limit;
    @tracked sort = '-created_at';
    @tracked public_id;
    @tracked name;

    /** action buttons */
    @tracked actionButtons = [
        {
            icon: 'refresh',
            onClick: this.deviceEventActions.refresh,
            helpText: this.intl.t('common.refresh'),
        },
    ];

    /** bulk action buttons */
    @tracked bulkActions = [];

    /** columns */
    @tracked columns = [
        {
            label: this.intl.t('column.name'),
            valuePath: 'name',
            width: '180px',
            cellComponent: 'table/cell/anchor',
            cellClassNames: 'uppercase',
            action: this.deviceEventActions.transition.view,
            permission: 'fleet-ops view device-event',
            hidden: true,
            resizable: true,
            sortable: true,
            filterable: true,
            filterParam: 'name',
            filterComponent: 'filter/string',
        },
        {
            label: this.intl.t('column.created-at'),
            valuePath: 'createdAt',
            sortParam: 'created_at',
            width: '10%',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/date',
        },
        {
            label: this.intl.t('column.updated-at'),
            valuePath: 'updatedAt',
            sortParam: 'updated_at',
            width: '10%',
            resizable: true,
            sortable: true,
            hidden: true,
            filterable: true,
            filterComponent: 'filter/date',
        },

        {
            label: '',
            cellComponent: 'table/cell/dropdown',
            ddButtonText: false,
            ddButtonIcon: 'ellipsis-h',
            ddButtonIconPrefix: 'fas',
            ddMenuLabel: this.intl.t('common.resource-actions', { resource: this.intl.t('resource.Device Event') }),
            cellClassNames: 'overflow-visible',
            wrapperClass: 'flex items-center justify-end mx-2',
            width: '10%',
            actions: [
                {
                    label: this.intl.t('column.view-details'),
                    fn: this.deviceEventActions.transition.view,
                    permission: 'fleet-ops view device-event',
                },
            ],
            sortable: false,
            filterable: false,
            resizable: false,
            searchable: false,
        },
    ];
}
