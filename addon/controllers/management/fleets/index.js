import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { get } from '@ember/object';
import { buildIdentityStub } from '../../../utils/identity-cell-resource';
import relationValue from '../../../utils/relation-value';
import { tracked } from '@glimmer/tracking';
import fleetOpsOptions from '../../../utils/fleet-ops-options';

export default class ManagementFleetsIndexController extends Controller {
    @service fleetActions;
    @service serviceAreaActions;
    @service zoneActions;
    @service vendorActions;
    @service tableContext;
    @service intl;

    /** query params */
    @tracked queryParams = ['page', 'limit', 'sort', 'query', 'public_id', 'zone', 'service_area', 'parent_fleet', 'vendor', 'created_by', 'updated_by', 'status', 'task', 'name'];
    @tracked page = 1;
    @tracked limit;
    @tracked sort = '-created_at';
    @tracked public_id;
    @tracked service_area;
    @tracked parent_fleet;
    @tracked vendor;
    @tracked zone;
    @tracked task;
    @tracked name;
    @tracked status;
    @tracked table;

    /** action buttons */
    get actionButtons() {
        return [
            {
                icon: 'refresh',
                onClick: this.fleetActions.refresh,
                helpText: this.intl.t('common.refresh'),
            },
            {
                text: this.intl.t('common.new'),
                type: 'primary',
                icon: 'plus',
                onClick: this.fleetActions.transition.create,
            },
            {
                text: this.intl.t('common.import'),
                type: 'magic',
                icon: 'upload',
                onClick: this.fleetActions.import,
            },
            {
                text: this.intl.t('common.export'),
                icon: 'long-arrow-up',
                iconClass: 'rotate-icon-45',
                wrapperClass: 'hidden md:flex',
                onClick: this.fleetActions.export,
            },
        ];
    }

    /** bulk actions */
    get bulkActions() {
        const selected = this.tableContext.getSelectedRows();

        return [
            {
                label: this.intl.t('common.delete-selected-count', { count: selected.length }),
                class: 'text-red-500',
                fn: this.fleetActions.bulkDelete,
            },
        ];
    }

    /** columns */
    get columns() {
        return [
            {
                sticky: true,
                label: this.intl.t('column.name'),
                valuePath: 'name',
                cellComponent: 'table/cell/anchor',
                permission: 'fleet-ops view fleet',
                action: this.fleetActions.transition.view,
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'name',
                filterComponent: 'filter/string',
            },
            {
                label: this.intl.t('column.service-area'),
                cellComponent: 'cell/service-area-identity',
                permission: 'fleet-ops view service-area',
                resourcePath: (fleet) => relationValue(fleet, 'service_area') ?? buildIdentityStub(fleet, { type: 'service-area', name: get(fleet, 'service_area.name'), load: () => fleet.get('service_area') }),
                valuePath: 'service_area.name',
                resizable: true,
                filterable: true,
                filterComponent: 'filter/model',
                filterComponentPlaceholder: 'Select service area',
                filterParam: 'service_area',
                model: 'service-area',
            },
            {
                label: this.intl.t('column.parent-fleet'),
                cellComponent: 'cell/fleet-identity',
                permission: 'fleet-ops view fleet',
                resourcePath: (fleet) => relationValue(fleet, 'parent_fleet'),
                valuePath: 'parent_fleet.name',
                resizable: true,
                filterable: true,
                filterComponent: 'filter/model',
                filterComponentPlaceholder: 'Select fleet',
                filterParam: 'parent_fleet_uuid',
                model: 'fleet',
            },
            {
                label: this.intl.t('column.vendor'),
                cellComponent: 'cell/vendor-identity',
                permission: 'fleet-ops view vendor',
                resourcePath: (fleet) => relationValue(fleet, 'vendor') ?? buildIdentityStub(fleet, { type: 'vendor', name: get(fleet, 'vendor.name'), load: () => fleet.get('vendor') }),
                valuePath: 'vendor.name',
                resizable: true,
                hidden: true,
                filterable: true,
                filterComponent: 'filter/model',
                filterComponentPlaceholder: 'Select vendor',
                filterParam: 'vendor',
                model: 'vendor',
            },
            {
                label: this.intl.t('column.zone'),
                cellComponent: 'cell/zone-identity',
                permission: 'fleet-ops view zone',
                resourcePath: (fleet) => relationValue(fleet, 'zone') ?? buildIdentityStub(fleet, { type: 'zone', name: get(fleet, 'zone.name'), load: () => fleet.get('zone') }),
                valuePath: 'zone.name',
                resizable: true,
                filterable: true,
                filterComponent: 'filter/model',
                filterComponentPlaceholder: 'Select zone',
                filterParam: 'zone',
                model: 'zone',
            },
            {
                label: this.intl.t('column.id'),
                valuePath: 'public_id',
                cellComponent: 'click-to-copy',
                action: this.fleetActions.transition.view,
                hidden: true,
                resizable: true,
                sortable: true,
                filterable: true,
                filterComponent: 'filter/string',
            },
            {
                label: this.intl.t('column.manpower'),
                valuePath: 'drivers_count',
                resizable: true,
                sortable: true,
                filterable: false,
            },
            {
                label: this.intl.t('column.active-manpower'),
                valuePath: 'drivers_online_count',
                hidden: true,
                resizable: true,
                sortable: true,
                filterable: false,
            },
            {
                label: this.intl.t('column.task'),
                valuePath: 'task',
                cellComponent: 'table/cell/base',
                resizable: true,
                sortable: true,
                filterable: true,
                filterComponent: 'filter/string',
            },
            {
                label: this.intl.t('column.status'),
                valuePath: 'status',
                cellComponent: 'table/cell/status',
                resizable: true,
                sortable: true,
                filterable: true,
                filterComponent: 'filter/multi-option',
                filterOptionLabel: 'label',
                filterOptionValue: 'value',
                filterOptions: fleetOpsOptions('fleetStatuses'),
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
                ddMenuLabel: this.intl.t('common.resource-actions', { resource: this.intl.t('resource.fleet') }),
                cellClassNames: 'overflow-visible',
                wrapperClass: 'flex items-center justify-end mx-2',
                sticky: 'right',
                width: 60,
                actions: [
                    {
                        label: this.intl.t('common.view-resource', { resource: this.intl.t('resource.fleet') }),
                        fn: this.fleetActions.transition.view,
                        permission: 'fleet-ops view fleet',
                    },
                    {
                        label: this.intl.t('common.edit-resource', { resource: this.intl.t('resource.fleet') }),
                        fn: this.fleetActions.transition.edit,
                        permission: 'fleet-ops update fleet',
                    },
                    {
                        label: this.intl.t('fleet.actions.assign-driver'),
                        permission: 'fleet-ops assign-driver-for fleet',
                        fn: this.fleetActions.assignDriver,
                    },
                    {
                        label: this.intl.t('fleet.actions.assign-vehicle'),
                        permission: 'fleet-ops assign-vehicle-for fleet',
                        fn: this.fleetActions.assignVehicle,
                    },
                    {
                        separator: true,
                    },
                    {
                        label: this.intl.t('common.delete-resource', { resource: this.intl.t('resource.fleet') }),
                        fn: this.fleetActions.delete,
                        permission: 'fleet-ops delete fleet',
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
