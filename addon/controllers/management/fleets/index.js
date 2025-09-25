import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { tracked } from '@glimmer/tracking';
import fleetOpsOptions from '../../../utils/fleet-ops-options';

export default class ManagementFleetsIndexController extends Controller {
    @service fleetActions;
    @service intl;
    @service serviceAreas;
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
    @tracked actionButtons = [
        {
            icon: 'refresh',
            onClick: this.fleetActions.refresh,
            helpText: this.intl.t('fleet-ops.common.reload-data'),
        },
        {
            text: 'New',
            type: 'primary',
            icon: 'plus',
            onClick: this.fleetActions.transition.create,
        },
        {
            text: 'Import',
            type: 'magic',
            icon: 'upload',
            onClick: this.fleetActions.import,
        },
        {
            text: 'Export',
            icon: 'long-arrow-up',
            iconClass: 'rotate-icon-45',
            wrapperClass: 'hidden md:flex',
            onClick: this.fleetActions.export,
        },
    ];
    @tracked bulkActions = [
        {
            label: 'Delete selected...',
            class: 'text-red-500',
            fn: this.fleetActions.bulkDelete,
        },
    ];
    @tracked columns = [
        {
            label: this.intl.t('fleet-ops.common.name'),
            valuePath: 'name',
            width: '150px',
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
            label: this.intl.t('fleet-ops.common.service-area'),
            cellComponent: 'table/cell/anchor',
            action: this.viewServiceArea.bind(this),
            permission: 'fleet-ops view service-area',
            valuePath: 'service_area.name',
            resizable: true,
            width: '130px',
            filterable: true,
            filterComponent: 'filter/model',
            filterComponentPlaceholder: 'Select service area',
            filterParam: 'service_area',
            model: 'service-area',
        },
        {
            label: this.intl.t('fleet-ops.common.parent-fleet'),
            cellComponent: 'table/cell/anchor',
            permission: 'fleet-ops view fleet',
            // action: this.viewParentFleet.bind(this),
            valuePath: 'parent_fleet.name',
            resizable: true,
            width: '130px',
            filterable: true,
            filterComponent: 'filter/model',
            filterComponentPlaceholder: 'Select fleet',
            filterParam: 'parent_fleet_uuid',
            model: 'fleet',
        },
        {
            label: this.intl.t('fleet-ops.common.vendor'),
            cellComponent: 'table/cell/anchor',
            permission: 'fleet-ops view vendor',
            // action: this.viewVendor.bind(this),
            valuePath: 'vendor.name',
            resizable: true,
            width: '130px',
            filterable: true,
            filterComponent: 'filter/model',
            filterComponentPlaceholder: 'Select vendor',
            filterParam: 'vendor',
            model: 'vendor',
        },
        {
            label: this.intl.t('fleet-ops.common.zone'),
            cellComponent: 'table/cell/anchor',
            permission: 'fleet-ops view zone',
            action: this.viewZone.bind(this),
            valuePath: 'zone.name',
            resizable: true,
            width: '130px',
            filterable: true,
            filterComponent: 'filter/model',
            filterComponentPlaceholder: 'Select zone',
            filterParam: 'zone',
            model: 'zone',
        },
        {
            label: this.intl.t('fleet-ops.common.id'),
            valuePath: 'public_id',
            width: '120px',
            cellComponent: 'click-to-copy',
            action: this.fleetActions.transition.view,
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/string',
        },
        {
            label: this.intl.t('fleet-ops.common.manpower'),
            valuePath: 'drivers_count',
            width: '100px',
            resizable: true,
            sortable: true,
            filterable: false,
        },
        {
            label: this.intl.t('fleet-ops.common.active-manpower'),
            valuePath: 'drivers_online_count',
            width: '120px',
            resizable: true,
            sortable: true,
            filterable: false,
        },
        {
            label: this.intl.t('fleet-ops.common.task'),
            valuePath: 'task',
            cellComponent: 'table/cell/base',
            width: '120px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/string',
        },
        {
            label: this.intl.t('fleet-ops.common.status'),
            valuePath: 'status',
            cellComponent: 'table/cell/status',
            width: '100px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/multi-option',
            filterOptionLabel: 'label',
            filterOptionValue: 'value',
            filterOptions: fleetOpsOptions('fleetStatuses'),
        },
        {
            label: this.intl.t('fleet-ops.common.created-at'),
            valuePath: 'createdAt',
            sortParam: 'created_at',
            width: '120px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/date',
        },
        {
            label: this.intl.t('fleet-ops.common.updated-at'),
            valuePath: 'updatedAt',
            sortParam: 'updated_at',
            width: '120px',
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
            ddMenuLabel: 'Fleet Actions',
            cellClassNames: 'overflow-visible',
            wrapperClass: 'flex items-center justify-end mx-2',
            width: '10%',
            actions: [
                {
                    label: this.intl.t('fleet-ops.management.fleets.index.view-fleet'),
                    fn: this.fleetActions.transition.view,
                    permission: 'fleet-ops view fleet',
                },
                {
                    label: this.intl.t('fleet-ops.management.fleets.index.edit-fleet'),
                    fn: this.fleetActions.transition.edit,
                    permission: 'fleet-ops update fleet',
                },
                {
                    label: this.intl.t('fleet-ops.management.fleets.index.assign-driver'),
                    permission: 'fleet-ops assign-driver-for fleet',
                    fn: () => {},
                },
                {
                    separator: true,
                },
                {
                    label: this.intl.t('fleet-ops.management.fleets.index.delete-fleet'),
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

    @action viewServiceArea(fleet, options = {}) {
        this.serviceAreas.viewServiceAreaInDialog(fleet.get('service_area'), options);
    }

    @action viewZone(fleet, options = {}) {
        this.serviceAreas.viewZoneInDialog(fleet.zone, options);
    }
}
