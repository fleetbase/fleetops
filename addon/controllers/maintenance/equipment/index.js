import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';

export default class MaintenanceEquipmentIndexController extends Controller {
    @service equipmentActions;
    @service intl;
    @service appCache;

    @tracked queryParams = ['type', 'status', 'page', 'limit', 'sort', 'query', 'public_id', 'created_at', 'updated_at'];
    @tracked page = 1;
    @tracked limit;
    @tracked sort = '-created_at';
    @tracked public_id;
    @tracked type;
    @tracked status;
    @tracked table;
    @tracked layout = this.appCache.get('fleetops:equipment:layout', 'table');

    /* eslint-disable ember/no-side-effects */
    get actionButtons() {
        return [
            {
                component: 'dropdown-button',
                icon: 'display',
                size: 'xs',
                items: [
                    {
                        label: this.intl.t('common.table-view'),
                        icon: 'table-list',
                        onClick: () => {
                            this.layout = 'table';
                            this.appCache.set('fleetops:equipment:layout', 'table');
                        },
                    },
                    {
                        label: this.intl.t('common.grid-view'),
                        icon: 'grip',
                        onClick: () => {
                            this.layout = 'grid';
                            this.appCache.set('fleetops:equipment:layout', 'grid');
                        },
                    },
                ],
                renderInPlace: true,
                helpText: 'Change the layout',
            },
            { icon: 'refresh', onClick: this.equipmentActions.refresh, helpText: this.intl.t('common.refresh') },
            { text: this.intl.t('common.new'), type: 'primary', icon: 'plus', onClick: this.equipmentActions.transition.create },
            { text: this.intl.t('common.import'), type: 'magic', icon: 'upload', onClick: this.equipmentActions.import },
            { text: this.intl.t('common.export'), icon: 'long-arrow-up', iconClass: 'rotate-icon-45', wrapperClass: 'hidden md:flex', onClick: this.equipmentActions.export },
        ];
    }

    get bulkActions() {
        return [{ label: 'Delete selected...', class: 'text-red-500', fn: this.equipmentActions.bulkDelete }];
    }

    get columns() {
        return [
            {
                label: this.intl.t('column.name'),
                valuePath: 'name',
                cellComponent: 'table/cell/anchor',
                cellClassNames: 'uppercase',
                action: this.equipmentActions.transition.view,
                permission: 'fleet-ops view equipment',
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'name',
                filterComponent: 'filter/string',
            },
            { label: this.intl.t('column.code'), valuePath: 'code', resizable: true, sortable: true, filterable: true, filterParam: 'code', filterComponent: 'filter/string' },
            {
                label: this.intl.t('column.type'),
                valuePath: 'type',
                cellComponent: 'table/cell/base',
                humanize: true,
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'type',
                filterComponent: 'filter/string',
            },
            {
                label: this.intl.t('column.status'),
                valuePath: 'status',
                cellComponent: 'table/cell/status',
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'status',
                filterComponent: 'filter/string',
            },
            { label: this.intl.t('column.serial-number'), valuePath: 'serial_number', resizable: true, sortable: true },
            { label: this.intl.t('column.manufacturer'), valuePath: 'manufacturer', resizable: true, sortable: true },
            { label: this.intl.t('column.created-at'), valuePath: 'createdAt', sortParam: 'created_at', resizable: true, sortable: true, filterable: true, filterComponent: 'filter/date' },
            {
                label: this.intl.t('column.updated-at'),
                valuePath: 'updatedAt',
                sortParam: 'updated_at',
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
                ddMenuLabel: this.intl.t('common.resource-actions', { resource: this.intl.t('resource.equipment') }),
                cellClassNames: 'overflow-visible',
                wrapperClass: 'flex items-center justify-end mx-2',
                actions: [
                    {
                        label: this.intl.t('common.view-resource', { resource: this.intl.t('resource.equipment') }),
                        fn: this.equipmentActions.transition.view,
                        permission: 'fleet-ops view equipment',
                    },
                    {
                        label: this.intl.t('common.edit-resource', { resource: this.intl.t('resource.equipment') }),
                        fn: this.equipmentActions.transition.edit,
                        permission: 'fleet-ops update equipment',
                    },
                    { separator: true },
                    {
                        label: this.intl.t('common.delete-resource', { resource: this.intl.t('resource.equipment') }),
                        fn: this.equipmentActions.delete,
                        permission: 'fleet-ops delete equipment',
                    },
                ],
                sortable: false,
                filterable: false,
                resizable: false,
                searchable: false,
            },
        ];
    }
}
