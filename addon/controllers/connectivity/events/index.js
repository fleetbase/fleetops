import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';

export default class ConnectivityEventsIndexController extends Controller {
    @service deviceEventActions;
    @service deviceActions;
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
            sticky: true,
            label: 'Event',
            valuePath: 'event_type',
            cellComponent: 'table/cell/anchor',
            cellClassNames: 'uppercase',
            action: this.deviceEventActions.transition.view,
            permission: 'fleet-ops view device-event',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/string',
        },
        {
            label: 'Device',
            valuePath: 'device.displayName',
            cellComponent: 'table/cell/anchor',
            action: this.deviceActions.transition.view,
            permission: 'fleet-ops view device',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/model',
            filterComponentPlaceholder: 'Select device',
            filterParam: 'device',
            model: 'device',
        },
        {
            label: 'Provider',
            valuePath: 'provider',
            resizable: true,
            sortable: true,
            filterable: true,
            filterParam: 'provider',
            filterComponent: 'filter/string',
        },
        {
            label: 'Severity',
            valuePath: 'severity',
            resizable: true,
            sortable: true,
            filterable: true,
            filterParam: 'severity',
            filterComponent: 'filter/string',
        },
        {
            label: 'IDENT',
            valuePath: 'ident',
            hidden: true,
            resizable: true,
            sortable: true,
        },
        {
            label: 'Protocol',
            valuePath: 'protocol',
            hidden: true,
            resizable: true,
            sortable: true,
        },
        {
            label: 'State',
            valuePath: 'state',
            hidden: true,
            resizable: true,
            sortable: true,
        },
        {
            label: 'Code',
            valuePath: 'code',
            resizable: true,
            sortable: true,
            filterable: true,
            filterParam: 'code',
            filterComponent: 'filter/string',
        },
        {
            label: this.intl.t('column.created-at'),
            valuePath: 'createdAt',
            sortParam: 'created_at',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/date',
        },
        {
            label: '',
            cellComponent: 'table/cell/dropdown',
            ddButtonText: false,
            ddButtonIcon: 'ellipsis-h',
            ddButtonIconPrefix: 'fas',
            ddMenuLabel: this.intl.t('common.resource-actions', { resource: this.intl.t('resource.device-event') }),
            cellClassNames: 'overflow-visible',
            wrapperClass: 'flex items-center justify-end mx-2',
            sticky: 'right',
            width: 60,
            actions: [
                {
                    label: this.intl.t('common.view-resource', { resource: this.intl.t('resource.device-event') }),
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
