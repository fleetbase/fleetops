import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';

export default class ManagementTrailersIndexController extends Controller {
    @service trailerActions;
    @service intl;
    @service appCache;
    queryParams = [
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
        'vendor',
        'serial_number',
        'length',
        'axle_count',
        'gvwr',
        'payload_capacity',
        'ownership_type',
        'devices_count',
        'equipment_count',
        'last_online_at',
        'created_at',
        'updated_at',
    ];
    @tracked page = 1;
    @tracked limit;
    @tracked sort = '-created_at';
    @tracked query;
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
    @tracked vendor;
    @tracked serial_number;
    @tracked length;
    @tracked axle_count;
    @tracked gvwr;
    @tracked payload_capacity;
    @tracked ownership_type;
    @tracked devices_count;
    @tracked equipment_count;
    @tracked last_online_at;
    @tracked created_at;
    @tracked updated_at;
    @tracked table;
    @tracked layout = this.appCache.get('fleetops:trailers:layout', 'table');

    get actionButtons() {
        return [
            {
                component: 'dropdown-button',
                icon: 'display',
                size: 'xs',
                items: [
                    { label: this.intl.t('common.table-view'), icon: 'table-list', onClick: () => this.setLayout('table') },
                    { label: this.intl.t('common.grid-view'), icon: 'grip', onClick: () => this.setLayout('grid') },
                ],
                renderInPlace: true,
                helpText: this.intl.t('common.change-layout'),
            },
            { icon: 'refresh', onClick: this.trailerActions.refresh, helpText: this.intl.t('common.refresh') },
            { text: this.intl.t('trailer.actions.new'), type: 'primary', icon: 'plus', onClick: this.trailerActions.transition.create, permission: 'fleet-ops create trailer' },
            { text: this.intl.t('common.import'), type: 'magic', icon: 'upload', onClick: this.trailerActions.import, permission: 'fleet-ops import trailer' },
            { text: this.intl.t('common.export'), icon: 'long-arrow-up', onClick: this.trailerActions.export, permission: 'fleet-ops export trailer' },
        ];
    }
    get columns() {
        const field = (label, valuePath, filterParam = valuePath, hidden = false) => ({
            label,
            valuePath,
            filterParam,
            hidden,
            cellComponent: 'table/cell/base',
            sortable: true,
            filterable: true,
            filterComponent: 'filter/string',
            resizable: true,
        });
        return [
            {
                ...field(this.intl.t('column.name'), 'displayName', 'name'),
                sticky: true,
                photoPath: 'photo_url',
                cellComponent: 'cell/vehicle-identity',
                compact: true,
                action: this.trailerActions.transition.view,
                permission: 'fleet-ops view trailer',
                showOnlineIndicator: true,
            },
            field(this.intl.t('trailer.fields.code'), 'code'),
            {
                label: this.intl.t('trailer.fields.type'),
                valuePath: 'type',
                cellComponent: 'table/cell/base',
                sortable: true,
                filterable: true,
                filterParam: 'trailer_type',
                filterComponent: 'filter/multi-option',
                filterOptions: this.trailerTypeOptions,
            },
            field(this.intl.t('column.plate-number'), 'plate_number'),
            {
                label: this.intl.t('column.status'),
                valuePath: 'status',
                cellComponent: 'table/cell/status',
                sortable: true,
                filterable: true,
                filterParam: 'status',
                filterComponent: 'filter/multi-option',
                filterOptions: this.statusOptions,
            },
            {
                label: this.intl.t('trailer.fields.vehicle'),
                valuePath: 'current_vehicle_name',
                cellComponent: 'table/cell/base',
                filterable: true,
                filterParam: 'vehicle',
                filterComponent: 'filter/model',
                model: 'vehicle',
            },
            {
                label: this.intl.t('trailer.fields.attachment-state'),
                valuePath: 'attachment_state',
                cellComponent: 'table/cell/status',
                filterable: true,
                filterParam: 'attachment_state',
                filterComponent: 'filter/multi-option',
                filterOptions: this.attachmentOptions,
            },
            {
                label: this.intl.t('trailer.fields.connectivity'),
                valuePath: 'connectivity_status',
                cellComponent: 'table/cell/status',
                filterable: true,
                filterParam: 'connectivity_status',
                filterComponent: 'filter/multi-option',
                filterOptions: this.connectivityOptions,
            },
            { ...field(this.intl.t('common.location'), 'current_location'), filterable: false },
            field(this.intl.t('trailer.fields.devices-count'), 'devices_count'),
            field(this.intl.t('trailer.fields.equipment-count'), 'equipment_count'),
            field(this.intl.t('column.updated-at'), 'updatedAt', 'updated_at'),
            field(this.intl.t('column.id'), 'public_id', 'public_id', true),
            field(this.intl.t('trailer.fields.vin'), 'vin', 'vin', true),
            field(this.intl.t('column.make'), 'make', 'trailer_make', true),
            field(this.intl.t('column.model'), 'model', 'trailer_model', true),
            field(this.intl.t('column.year'), 'year', 'trailer_year', true),
            field(this.intl.t('trailer.fields.serial-number'), 'serial_number', 'serial_number', true),
            field(this.intl.t('trailer.fields.vendor'), 'vendor_name', 'vendor', true),
            field(this.intl.t('trailer.fields.length'), 'length', 'length', true),
            field(this.intl.t('trailer.fields.axles'), 'axle_count', 'axle_count', true),
            field(this.intl.t('trailer.fields.gvwr'), 'gvwr', 'gvwr', true),
            field(this.intl.t('trailer.fields.payload'), 'payload_capacity', 'payload_capacity', true),
            field(this.intl.t('trailer.fields.ownership'), 'ownership_type', 'ownership_type', true),
            field(this.intl.t('trailer.fields.last-online'), 'last_online_at', 'last_online_at', true),
            field(this.intl.t('column.created-at'), 'createdAt', 'created_at', true),
            {
                label: '',
                cellComponent: 'table/cell/dropdown',
                sticky: 'right',
                width: 60,
                actions: [
                    { label: this.intl.t('common.view'), fn: this.trailerActions.transition.view, permission: 'fleet-ops view trailer' },
                    { label: this.intl.t('common.edit'), fn: this.trailerActions.transition.edit, permission: 'fleet-ops update trailer' },
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
                    { label: this.intl.t('trailer.actions.attach-device'), fn: this.trailerActions.attachDevice, permission: 'fleet-ops attach-device-for trailer' },
                    { separator: true },
                    { label: this.intl.t('common.delete'), fn: this.trailerActions.delete, permission: 'fleet-ops delete trailer', class: 'text-red-500' },
                ],
            },
        ];
    }
    get trailerTypeOptions() {
        return [
            'dry_van',
            'reefer',
            'flatbed',
            'step_deck',
            'lowboy',
            'tanker',
            'bulk',
            'dump',
            'chassis',
            'curtain_side',
            'car_carrier',
            'livestock',
            'logging',
            'dolly',
            'specialty',
            'other',
        ].map((value) => ({ value, label: this.intl.t(`trailer.types.${value}`) }));
    }
    get statusOptions() {
        return ['available', 'in_use', 'maintenance', 'out_of_service', 'retired'].map((value) => ({ value, label: this.intl.t(`trailer.statuses.${value}`) }));
    }
    get attachmentOptions() {
        return ['attached', 'detached'].map((value) => ({ value, label: this.intl.t(`trailer.attachment.${value}`) }));
    }
    get connectivityOptions() {
        return ['online', 'recently_offline', 'offline', 'never_connected'].map((value) => ({ value, label: this.intl.t(`trailer.connectivity.${value}`) }));
    }
    setLayout(layout) {
        this.layout = layout;
        this.appCache.set('fleetops:trailers:layout', layout);
    }
}
