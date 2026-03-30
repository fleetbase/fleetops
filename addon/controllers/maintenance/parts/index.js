import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';

export default class MaintenancePartsIndexController extends Controller {
    @service partActions;
    @service intl;

    @tracked queryParams = ['type', 'status', 'page', 'limit', 'sort', 'query', 'public_id', 'created_at', 'updated_at'];
    @tracked page = 1;
    @tracked limit;
    @tracked sort = '-created_at';
    @tracked public_id;
    @tracked type;
    @tracked status;

    @tracked actionButtons = [
        { icon: 'refresh', onClick: this.partActions.refresh, helpText: this.intl.t('common.refresh') },
        { text: this.intl.t('common.new'), type: 'primary', icon: 'plus', onClick: this.partActions.transition.create },
        { text: this.intl.t('common.export'), icon: 'long-arrow-up', iconClass: 'rotate-icon-45', wrapperClass: 'hidden md:flex', onClick: this.partActions.export },
    ];

    @tracked bulkActions = [{ label: 'Delete selected...', class: 'text-red-500', fn: this.partActions.bulkDelete }];

    @tracked columns = [
        { label: this.intl.t('column.name'), valuePath: 'name', cellComponent: 'table/cell/anchor', cellClassNames: 'uppercase', action: this.partActions.transition.view, permission: 'fleet-ops view part', resizable: true, sortable: true, filterable: true, filterParam: 'name', filterComponent: 'filter/string' },
        { label: this.intl.t('column.part-number'), valuePath: 'part_number', resizable: true, sortable: true, filterable: true, filterParam: 'part_number', filterComponent: 'filter/string' },
        { label: this.intl.t('column.type'), valuePath: 'type', cellComponent: 'table/cell/humanize', resizable: true, sortable: true, filterable: true, filterParam: 'type', filterComponent: 'filter/string' },
        { label: this.intl.t('column.status'), valuePath: 'status', cellComponent: 'table/cell/status', resizable: true, sortable: true, filterable: true, filterParam: 'status', filterComponent: 'filter/string' },
        { label: this.intl.t('column.quantity-on-hand'), valuePath: 'quantity_on_hand', resizable: true, sortable: true },
        { label: this.intl.t('column.unit-cost'), valuePath: 'unit_cost', cellComponent: 'table/cell/currency', resizable: true, sortable: true },
        { label: this.intl.t('column.created-at'), valuePath: 'createdAt', sortParam: 'created_at', resizable: true, sortable: true, filterable: true, filterComponent: 'filter/date' },
        { label: this.intl.t('column.updated-at'), valuePath: 'updatedAt', sortParam: 'updated_at', resizable: true, sortable: true, hidden: true, filterable: true, filterComponent: 'filter/date' },
        {
            label: '',
            cellComponent: 'table/cell/dropdown',
            ddButtonText: false,
            ddButtonIcon: 'ellipsis-h',
            ddButtonIconPrefix: 'fas',
            ddMenuLabel: this.intl.t('common.resource-actions', { resource: this.intl.t('resource.part') }),
            cellClassNames: 'overflow-visible',
            wrapperClass: 'flex items-center justify-end mx-2',
            actions: [
                { label: this.intl.t('column.view-details'), fn: this.partActions.transition.view, permission: 'fleet-ops view part' },
                { label: this.intl.t('column.edit-place'), fn: this.partActions.transition.edit, permission: 'fleet-ops update part' },
                { separator: true },
                { label: this.intl.t('column.delete'), fn: this.partActions.delete, permission: 'fleet-ops delete part' },
            ],
            sortable: false, filterable: false, resizable: false, searchable: false,
        },
    ];
}
