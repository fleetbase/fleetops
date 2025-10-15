import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';

export default class ConnectivityTelematicsIndexController extends Controller {
    @service telematicActions;
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
            onClick: this.telematicActions.refresh,
            helpText: this.intl.t('common.refresh'),
        },
        {
            text: this.intl.t('common.new'),
            type: 'primary',
            icon: 'plus',
            onClick: this.telematicActions.transition.create,
        },
        {
            text: this.intl.t('common.import'),
            type: 'magic',
            icon: 'upload',
            onClick: this.telematicActions.import,
        },
        {
            text: this.intl.t('common.export'),
            icon: 'long-arrow-up',
            iconClass: 'rotate-icon-45',
            wrapperClass: 'hidden md:flex',
            onClick: this.telematicActions.export,
        },
    ];

    /** bulk action buttons */
    @tracked bulkActions = [
        {
            label: 'Delete selected...',
            class: 'text-red-500',
            fn: this.telematicActions.bulkDelete,
        },
    ];

    /** columns */
    @tracked columns = [
        {
            label: this.intl.t('column.name'),
            valuePath: 'name',
            width: '180px',
            cellComponent: 'table/cell/anchor',
            cellClassNames: 'uppercase',
            action: this.telematicActions.transition.view,
            permission: 'fleet-ops view telematic',
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
            ddMenuLabel: this.intl.t('common.resource-actions', { resource: this.intl.t('resource.Telematic') }),
            cellClassNames: 'overflow-visible',
            wrapperClass: 'flex items-center justify-end mx-2',
            width: '10%',
            actions: [
                {
                    label: this.intl.t('column.view-details'),
                    fn: this.telematicActions.transition.view,
                    permission: 'fleet-ops view telematic',
                },
                {
                    label: this.intl.t('column.edit-place'),
                    fn: this.telematicActions.transition.edit,
                    permission: 'fleet-ops update telematic',
                },
                {
                    separator: true,
                },
                {
                    label: this.intl.t('column.delete'),
                    fn: this.telematicActions.delete,
                    permission: 'fleet-ops delete telematic',
                },
            ],
            sortable: false,
            filterable: false,
            resizable: false,
            searchable: false,
        },
    ];
}
