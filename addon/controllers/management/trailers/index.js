import Controller from '@ember/controller';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { TRAILER_TYPES, TRAILER_STATUSES, TRAILER_OWNERSHIP_TYPES } from '../../../components/trailer/form';

export default class ManagementTrailersIndexController extends Controller {
    @service trailerActions;
    @service vehicleActions;
    @service vendorActions;
    @service tableContext;
    @service intl;
    @service appCache;
    @service store;

    /** query params */
    @tracked queryParams = [
        'page',
        'limit',
        'sort',
        'query',
        'public_id',
        'name',
        'code',
        'trailer_type',
        'status',
        'attachment_state',
        'vehicle',
        'connectivity_status',
        'trailer_make',
        'trailer_model',
        'trailer_year',
        'plate_number',
        'vin',
        'serial_number',
        'vendor',
        'ownership_type',
        'refrigerated',
        'device',
        'last_online_at',
        'created_at',
        'updated_at',
    ];
    @tracked query = null;
    @tracked page = 1;
    @tracked limit;
    @tracked sort = '-created_at';
    @tracked public_id;
    @tracked name;
    @tracked code;
    @tracked trailer_type;
    @tracked status;
    @tracked attachment_state;
    @tracked vehicle;
    @tracked connectivity_status;
    @tracked trailer_make;
    @tracked trailer_model;
    @tracked trailer_year;
    @tracked plate_number;
    @tracked vin;
    @tracked serial_number;
    @tracked vendor;
    @tracked ownership_type;
    @tracked refrigerated;
    @tracked device;
    @tracked last_online_at;
    @tracked created_at;
    @tracked updated_at;
    @tracked table;
    @tracked layout = this.appCache.get('fleetops:trailers:layout', 'table');

    /** action buttons */
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
                        onClick: () => this.setLayout('table'),
                    },
                    {
                        label: this.intl.t('common.grid-view'),
                        icon: 'grip',
                        onClick: () => this.setLayout('grid'),
                    },
                ],
                renderInPlace: true,
                helpText: this.intl.t('common.change-layout'),
            },
            {
                icon: 'refresh',
                onClick: this.trailerActions.refresh,
                helpText: this.intl.t('common.refresh'),
            },
            {
                text: this.intl.t('common.new'),
                type: 'primary',
                icon: 'plus',
                onClick: this.trailerActions.transition.create,
                permission: 'fleet-ops create trailer',
            },
            {
                text: this.intl.t('common.import'),
                type: 'magic',
                icon: 'upload',
                onClick: this.trailerActions.import,
                permission: 'fleet-ops import trailer',
            },
            {
                text: this.intl.t('common.export'),
                icon: 'long-arrow-up',
                iconClass: 'rotate-icon-45',
                wrapperClass: 'hidden md:flex',
                onClick: this.trailerActions.export,
                permission: 'fleet-ops export trailer',
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
                fn: this.trailerActions.bulkDelete,
                permission: 'fleet-ops delete trailer',
            },
        ];
    }

    get trailerTypeOptions() {
        return TRAILER_TYPES.map((value) => ({ value, label: this.intl.t(`trailer.types.${value}`) }));
    }

    get statusOptions() {
        return TRAILER_STATUSES.map((value) => ({ value, label: this.intl.t(`trailer.statuses.${value}`) }));
    }

    get attachmentOptions() {
        return ['attached', 'detached'].map((value) => ({ value, label: this.intl.t(`trailer.attachment.${value}`) }));
    }

    get connectivityOptions() {
        return ['online', 'recently_offline', 'offline', 'never_connected'].map((value) => ({ value, label: this.intl.t(`trailer.connectivity.${value}`) }));
    }

    get ownershipOptions() {
        return TRAILER_OWNERSHIP_TYPES.map((value) => ({ value, label: this.intl.t(`trailer.ownership-types.${value}`) }));
    }

    /** columns */
    get columns() {
        return [
            {
                sticky: true,
                label: this.intl.t('trailer.columns.name'),
                valuePath: 'displayName',
                photoPath: 'photo_url',
                cellComponent: 'cell/vehicle-identity',
                compact: true,
                permission: 'fleet-ops view trailer',
                action: this.trailerActions.transition.view,
                resizable: true,
                sortable: true,
                filterable: true,
                filterComponent: 'filter/string',
                filterParam: 'name',
                showOnlineIndicator: true,
                showStatus: false,
            },
            {
                label: this.intl.t('trailer.columns.type'),
                valuePath: 'type',
                cellComponent: 'cell/translated-value',
                translationPrefix: 'trailer.types',
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'trailer_type',
                filterComponent: 'filter/multi-option',
                filterOptions: this.trailerTypeOptions,
                filterOptionLabel: 'label',
                filterOptionValue: 'value',
                width: 130,
            },
            {
                label: this.intl.t('trailer.columns.plate-number'),
                valuePath: 'plate_number',
                cellComponent: 'table/cell/base',
                action: this.trailerActions.transition.view,
                resizable: true,
                sortable: true,
                filterable: true,
                filterComponent: 'filter/string',
                filterParam: 'plate_number',
            },
            {
                label: this.intl.t('trailer.columns.code'),
                valuePath: 'code',
                cellComponent: 'table/cell/base',
                resizable: true,
                sortable: true,
                filterable: true,
                filterComponent: 'filter/string',
                filterParam: 'code',
            },
            {
                label: this.intl.t('trailer.columns.vehicle'),
                valuePath: 'current_vehicle_name',
                cellComponent: 'table/cell/anchor',
                permission: 'fleet-ops view vehicle',
                action: async (trailer) => {
                    const vehicle = trailer.current_vehicle ?? (trailer.current_vehicle_id ? await this.store.findRecord('vehicle', trailer.current_vehicle_id) : null);

                    if (vehicle) {
                        return this.vehicleActions.panel.view(vehicle);
                    }
                },
                emptyText: '-',
                resizable: true,
                filterable: true,
                filterComponent: 'filter/model',
                filterComponentPlaceholder: this.intl.t('trailer.placeholders.filter-vehicle'),
                filterParam: 'vehicle',
                model: 'vehicle',
            },
            {
                label: this.intl.t('trailer.columns.attachment-state'),
                valuePath: 'attachment_state',
                cellComponent: 'cell/translated-value',
                translationPrefix: 'trailer.attachment',
                badge: true,
                badgeIcons: { attached: 'link', detached: 'link-slash' },
                resizable: true,
                filterable: true,
                filterParam: 'attachment_state',
                filterComponent: 'filter/multi-option',
                filterOptions: this.attachmentOptions,
                filterOptionLabel: 'label',
                filterOptionValue: 'value',
                width: 120,
            },
            {
                label: this.intl.t('trailer.columns.status'),
                valuePath: 'status',
                cellComponent: 'cell/translated-value',
                translationPrefix: 'trailer.statuses',
                badge: true,
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'status',
                filterComponent: 'filter/multi-option',
                filterOptions: this.statusOptions,
                filterOptionLabel: 'label',
                filterOptionValue: 'value',
                width: 120,
            },
            {
                label: this.intl.t('trailer.columns.connectivity'),
                valuePath: 'connectivity_status',
                cellComponent: 'cell/translated-value',
                translationPrefix: 'trailer.connectivity',
                badge: true,
                resizable: true,
                filterable: true,
                filterParam: 'connectivity_status',
                filterComponent: 'filter/multi-option',
                filterOptions: this.connectivityOptions,
                filterOptionLabel: 'label',
                filterOptionValue: 'value',
                width: 140,
            },
            {
                label: this.intl.t('trailer.columns.last-online'),
                valuePath: 'lastOnlineAt',
                sortParam: 'last_online_at',
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'last_online_at',
                filterLabel: this.intl.t('trailer.filters.last-online-between'),
                filterComponent: 'filter/date',
            },
            {
                label: this.intl.t('trailer.columns.location'),
                valuePath: 'location',
                cellComponent: 'table/cell/point',
                onClick: this.trailerActions.locate,
                resizable: true,
                hidden: true,
                filterable: false,
                sortable: false,
            },
            {
                label: this.intl.t('trailer.columns.devices'),
                valuePath: 'devices_count',
                cellComponent: 'table/cell/base',
                resizable: true,
                sortable: false,
                filterable: false,
                width: 90,
            },
            {
                label: this.intl.t('trailer.columns.equipment'),
                valuePath: 'equipment_count',
                cellComponent: 'table/cell/base',
                resizable: true,
                sortable: false,
                filterable: false,
                width: 100,
            },
            {
                label: this.intl.t('trailer.columns.id'),
                valuePath: 'public_id',
                cellComponent: 'click-to-copy',
                resizable: true,
                sortable: true,
                hidden: true,
                filterable: true,
                filterComponent: 'filter/string',
                filterParam: 'public_id',
            },
            {
                label: this.intl.t('trailer.columns.vin'),
                valuePath: 'vin',
                cellComponent: 'table/cell/base',
                resizable: true,
                sortable: true,
                hidden: true,
                filterable: true,
                filterComponent: 'filter/string',
                filterParam: 'vin',
            },
            {
                label: this.intl.t('trailer.columns.serial-number'),
                valuePath: 'serial_number',
                cellComponent: 'table/cell/base',
                resizable: true,
                sortable: true,
                hidden: true,
                filterable: true,
                filterComponent: 'filter/string',
                filterParam: 'serial_number',
            },
            {
                label: this.intl.t('trailer.columns.make'),
                valuePath: 'make',
                cellComponent: 'table/cell/base',
                resizable: true,
                sortable: true,
                hidden: true,
                filterable: true,
                filterComponent: 'filter/string',
                filterParam: 'trailer_make',
            },
            {
                label: this.intl.t('trailer.columns.model'),
                valuePath: 'model',
                cellComponent: 'table/cell/base',
                resizable: true,
                sortable: true,
                hidden: true,
                filterable: true,
                filterComponent: 'filter/string',
                filterParam: 'trailer_model',
            },
            {
                label: this.intl.t('trailer.columns.year'),
                valuePath: 'year',
                cellComponent: 'table/cell/base',
                resizable: true,
                sortable: true,
                hidden: true,
                filterable: true,
                filterComponent: 'filter/string',
                filterParam: 'trailer_year',
            },
            {
                label: this.intl.t('trailer.columns.vendor'),
                valuePath: 'vendor_name',
                cellComponent: 'table/cell/anchor',
                permission: 'fleet-ops view vendor',
                action: async ({ vendor_uuid }) => {
                    if (!vendor_uuid) {
                        return;
                    }

                    const vendor = await this.store.findRecord('vendor', vendor_uuid);
                    this.vendorActions.viewVendor(vendor);
                },
                hidden: true,
                resizable: true,
                filterable: true,
                filterComponent: 'filter/model',
                filterComponentPlaceholder: this.intl.t('trailer.placeholders.filter-vendor'),
                filterParam: 'vendor',
                model: 'vendor',
            },
            {
                label: this.intl.t('trailer.columns.ownership'),
                valuePath: 'ownership_type',
                cellComponent: 'cell/translated-value',
                translationPrefix: 'trailer.ownership-types',
                resizable: true,
                sortable: true,
                hidden: true,
                filterable: true,
                filterParam: 'ownership_type',
                filterComponent: 'filter/multi-option',
                filterOptions: this.ownershipOptions,
                filterOptionLabel: 'label',
                filterOptionValue: 'value',
            },
            {
                label: this.intl.t('trailer.columns.axles'),
                valuePath: 'axle_count',
                cellComponent: 'table/cell/base',
                resizable: true,
                sortable: true,
                hidden: true,
                filterable: false,
                width: 90,
            },
            {
                label: this.intl.t('trailer.columns.gvwr'),
                valuePath: 'gvwr',
                cellComponent: 'table/cell/base',
                resizable: true,
                sortable: true,
                hidden: true,
                filterable: false,
            },
            {
                label: this.intl.t('trailer.columns.payload-capacity'),
                valuePath: 'payload_capacity',
                cellComponent: 'table/cell/base',
                resizable: true,
                sortable: true,
                hidden: true,
                filterable: false,
            },
            {
                label: this.intl.t('trailer.columns.length'),
                valuePath: 'length',
                cellComponent: 'table/cell/base',
                resizable: true,
                sortable: true,
                hidden: true,
                filterable: false,
            },
            {
                label: this.intl.t('trailer.columns.created-at'),
                valuePath: 'createdAt',
                sortParam: 'created_at',
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'created_at',
                filterLabel: this.intl.t('trailer.filters.created-between'),
                filterComponent: 'filter/date',
            },
            {
                label: this.intl.t('trailer.columns.updated-at'),
                valuePath: 'updatedAt',
                sortParam: 'updated_at',
                resizable: true,
                sortable: true,
                hidden: true,
                filterable: true,
                filterParam: 'updated_at',
                filterLabel: this.intl.t('trailer.filters.updated-between'),
                filterComponent: 'filter/date',
            },
            {
                label: '',
                cellComponent: 'table/cell/dropdown',
                ddButtonText: false,
                ddButtonIcon: 'ellipsis-h',
                ddButtonIconPrefix: 'fas',
                ddMenuLabel: this.intl.t('common.resource-actions', { resource: this.intl.t('resource.trailer') }),
                cellClassNames: 'overflow-visible',
                wrapperClass: 'flex items-center justify-end mx-2',
                sticky: 'right',
                width: 60,
                actions: [
                    {
                        label: this.intl.t('common.view-resource', { resource: this.intl.t('resource.trailer') }),
                        fn: this.trailerActions.transition.view,
                        permission: 'fleet-ops view trailer',
                    },
                    {
                        label: this.intl.t('common.edit-resource', { resource: this.intl.t('resource.trailer') }),
                        fn: this.trailerActions.transition.edit,
                        permission: 'fleet-ops update trailer',
                    },
                    {
                        label: this.intl.t('trailer.actions.locate'),
                        fn: this.trailerActions.locate,
                        permission: 'fleet-ops view trailer',
                        isVisible: (trailer) => Boolean(trailer.hasValidCoordinates),
                    },
                    {
                        separator: true,
                    },
                    {
                        label: this.intl.t('trailer.actions.attach-vehicle'),
                        fn: this.trailerActions.attachVehicle,
                        permission: 'fleet-ops attach-vehicle-for trailer',
                        isVisible: (trailer) => trailer.attachment_state !== 'attached',
                    },
                    {
                        label: this.intl.t('trailer.actions.detach-vehicle'),
                        fn: this.trailerActions.detachVehicle,
                        permission: 'fleet-ops detach-vehicle-for trailer',
                        isVisible: (trailer) => trailer.attachment_state === 'attached',
                    },
                    {
                        label: this.intl.t('trailer.actions.attach-device'),
                        fn: this.trailerActions.attachDevice,
                        permission: 'fleet-ops attach-device-for trailer',
                    },
                    {
                        label: this.intl.t('trailer.actions.attach-equipment'),
                        fn: this.trailerActions.attachEquipment,
                        permission: 'fleet-ops attach-equipment-for trailer',
                    },
                    {
                        separator: true,
                    },
                    {
                        label: this.intl.t('trailer.actions.schedule-maintenance'),
                        fn: this.trailerActions.scheduleMaintenance,
                        permission: 'fleet-ops create maintenance-schedule',
                    },
                    {
                        label: this.intl.t('trailer.actions.create-work-order'),
                        fn: this.trailerActions.createWorkOrder,
                        permission: 'fleet-ops create work-order',
                    },
                    {
                        label: this.intl.t('trailer.actions.log-maintenance'),
                        fn: this.trailerActions.logMaintenance,
                        permission: 'fleet-ops create maintenance',
                    },
                    {
                        separator: true,
                    },
                    {
                        label: this.intl.t('common.delete-resource', { resource: this.intl.t('resource.trailer') }),
                        fn: this.trailerActions.delete,
                        permission: 'fleet-ops delete trailer',
                        class: 'text-red-500',
                    },
                ],
                sortable: false,
                filterable: false,
                resizable: false,
                searchable: false,
            },
            {
                label: this.intl.t('resource.devices'),
                valuePath: 'devices',
                hidden: true,
                filterable: true,
                filterComponent: 'filter/multi-model',
                filterComponentPlaceholder: this.intl.t('common.select-resource-filter-by', { resource: this.intl.t('resource.device') }),
                filterParam: 'device',
                model: 'device',
                modelNamePath: 'displayName',
            },
            {
                label: this.intl.t('trailer.columns.refrigerated'),
                valuePath: 'refrigerated',
                cellComponent: 'table/cell/checkbox',
                resizable: true,
                sortable: true,
                hidden: true,
                filterable: true,
                filterParam: 'refrigerated',
                filterComponent: 'filter/checkbox',
                noFilterLabel: true,
                width: 110,
            },
        ];
    }

    @action setLayout(layout) {
        this.layout = layout;
        this.appCache.set('fleetops:trailers:layout', layout);
    }
}
