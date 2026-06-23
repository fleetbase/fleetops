import Controller from '@ember/controller';
import { action, get } from '@ember/object';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import fleetOpsOptions from '../../../utils/fleet-ops-options';

function sensorTelematic(sensor) {
    const telematic = get(sensor, 'telematic');

    if (telematic) {
        return telematic;
    }

    const id = get(sensor, 'telematic_uuid');
    const name = get(sensor, 'telematic_name');
    const provider = get(sensor, 'provider');

    if (!id && !name && !provider) {
        return null;
    }

    return {
        id,
        name: name ?? provider,
        provider,
        provider_descriptor: get(sensor, 'provider_descriptor'),
    };
}

function sensorDevice(sensor) {
    const device = get(sensor, 'device');

    if (device) {
        return device;
    }

    const id = get(sensor, 'device_uuid');
    const deviceId = get(sensor, 'device_id') ?? get(sensor, 'provider_device_id') ?? get(sensor, 'ident');
    const name = get(sensor, 'device_name') ?? deviceId;
    const imei = get(sensor, 'device_imei') ?? get(sensor, 'imei') ?? get(sensor, 'ident');

    if (!id && !name && !imei && !deviceId) {
        return null;
    }

    return {
        id,
        displayName: name,
        name,
        imei,
        device_id: deviceId,
        ident: get(sensor, 'ident'),
        serial_number: get(sensor, 'device_serial_number'),
        connection_status: get(sensor, 'device_connection_status'),
        status: get(sensor, 'device_status'),
        photo_url: get(sensor, 'device_photo_url') ?? get(sensor, 'photo_url'),
    };
}

export default class ConnectivitySensorsIndexController extends Controller {
    @service sensorActions;
    @service deviceActions;
    @service telematicActions;
    @service intl;
    @service store;

    /** query params */
    @tracked queryParams = ['page', 'limit', 'sort', 'query', 'telematic', 'device', 'type', 'status', 'serial_number', 'imei', 'last_reading_at', 'created_at', 'updated_at'];
    @tracked page = 1;
    @tracked limit;
    @tracked sort = '-created_at';
    @tracked query;
    @tracked telematic;
    @tracked device;
    @tracked type;
    @tracked status;
    @tracked serial_number;
    @tracked imei;
    @tracked last_reading_at;
    @tracked created_at;
    @tracked updated_at;

    /** action buttons */
    @tracked actionButtons = [
        {
            icon: 'refresh',
            onClick: this.sensorActions.refresh,
            helpText: this.intl.t('common.refresh'),
        },
        {
            text: this.intl.t('common.new'),
            type: 'primary',
            icon: 'plus',
            onClick: this.sensorActions.transition.create,
        },
        {
            text: this.intl.t('common.import'),
            type: 'magic',
            icon: 'upload',
            onClick: this.sensorActions.import,
        },
        {
            text: this.intl.t('common.export'),
            icon: 'long-arrow-up',
            iconClass: 'rotate-icon-45',
            wrapperClass: 'hidden md:flex',
            onClick: this.sensorActions.export,
        },
    ];

    /** bulk action buttons */
    @tracked bulkActions = [
        {
            label: 'Delete selected...',
            class: 'text-red-500',
            fn: this.sensorActions.bulkDelete,
        },
    ];

    /** columns */
    @tracked columns = [
        {
            sticky: true,
            label: this.intl.t('column.name'),
            valuePath: 'displayName',
            cellComponent: 'table/cell/anchor',
            cellClassNames: 'uppercase',
            action: this.sensorActions.transition.view,
            permission: 'fleet-ops view sensor',
            resizable: true,
            sortable: true,
            filterable: true,
            filterParam: 'query',
            filterComponent: 'filter/string',
        },
        {
            label: 'Telematic',
            valuePath: 'telematic.provider',
            cellComponent: 'cell/telematic-provider',
            resourcePath: sensorTelematic,
            compact: true,
            action: this.openTelematic,
            permission: 'fleet-ops view telematic',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/model',
            filterComponentPlaceholder: 'Select telematic',
            filterParam: 'telematic',
            model: 'telematic',
        },
        {
            label: 'Device',
            valuePath: 'device.displayName',
            cellComponent: 'cell/device-identity',
            resourcePath: sensorDevice,
            showStatus: false,
            action: this.openDevice,
            permission: 'fleet-ops view device',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/model',
            filterComponentPlaceholder: 'Select device',
            filterParam: 'device',
            model: 'device',
            modelNamePath: 'displayName',
            query: this.telematic ? { telematic_uuid: this.telematic } : undefined,
        },
        {
            label: 'Type',
            valuePath: 'type',
            cellComponent: 'table/cell/base',
            humanize: true,
            resizable: true,
            sortable: true,
            filterable: true,
            filterParam: 'type',
            filterComponent: 'filter/multi-option',
            filterOptions: fleetOpsOptions('sensorTypes'),
            filterOptionLabel: 'label',
            filterOptionValue: 'value',
        },
        {
            label: 'Last Value',
            valuePath: 'last_value',
            resizable: true,
            sortable: true,
        },
        {
            label: 'Unit',
            valuePath: 'unit',
            resizable: true,
            sortable: true,
        },
        {
            label: this.intl.t('column.status'),
            valuePath: 'status',
            cellComponent: 'table/cell/status',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/multi-option',
            filterOptions: fleetOpsOptions('sensorStatuses'),
            filterOptionLabel: 'label',
            filterOptionValue: 'value',
        },
        {
            label: 'Threshold',
            valuePath: 'threshold_status',
            cellComponent: 'table/cell/status',
            resizable: true,
            sortable: true,
        },
        {
            label: 'Serial Number',
            valuePath: 'serial_number',
            resizable: true,
            sortable: true,
            filterable: true,
            filterParam: 'serial_number',
            filterComponent: 'filter/string',
        },
        {
            label: 'IMEI',
            valuePath: 'imei',
            resizable: true,
            sortable: true,
            filterable: true,
            filterParam: 'imei',
            filterComponent: 'filter/string',
        },
        {
            label: 'Last Reading',
            valuePath: 'lastReadingAt',
            sortParam: 'last_reading_at',
            resizable: true,
            sortable: true,
            filterable: true,
            filterParam: 'last_reading_at',
            filterComponent: 'filter/date',
        },
        {
            label: this.intl.t('column.created-at'),
            valuePath: 'createdAt',
            sortParam: 'created_at',
            resizable: true,
            sortable: true,
            filterable: true,
            filterParam: 'created_at',
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
            filterParam: 'updated_at',
            filterComponent: 'filter/date',
        },
        {
            label: '',
            cellComponent: 'table/cell/dropdown',
            ddButtonText: false,
            ddButtonIcon: 'ellipsis-h',
            ddButtonIconPrefix: 'fas',
            ddMenuLabel: this.intl.t('common.resource-actions', { resource: this.intl.t('resource.sensor') }),
            cellClassNames: 'overflow-visible',
            wrapperClass: 'flex items-center justify-end mx-2',
            sticky: 'right',
            width: 60,
            actions: [
                {
                    label: this.intl.t('common.view-resource', { resource: this.intl.t('resource.sensor') }),
                    fn: this.sensorActions.transition.view,
                    permission: 'fleet-ops view sensor',
                },
                {
                    label: this.intl.t('common.edit-resource', { resource: this.intl.t('resource.sensor') }),
                    fn: this.sensorActions.transition.edit,
                    permission: 'fleet-ops update sensor',
                },
                {
                    separator: true,
                },
                {
                    label: this.intl.t('common.delete-resource', { resource: this.intl.t('resource.sensor') }),
                    fn: this.sensorActions.delete,
                    permission: 'fleet-ops delete sensor',
                },
            ],
            sortable: false,
            filterable: false,
            resizable: false,
            searchable: false,
        },
    ];

    async resolveDevice(device) {
        if (!device?.id) {
            return null;
        }

        const cachedDevice = this.store.peekRecord('device', device.id);

        if (cachedDevice) {
            return cachedDevice;
        }

        try {
            return await this.store.findRecord('device', device.id);
        } catch (_) {
            return device;
        }
    }

    @action async openDevice(device) {
        const resolvedDevice = await this.resolveDevice(device);

        if (resolvedDevice?.id) {
            return this.deviceActions.panel.view(resolvedDevice);
        }
    }

    @action openTelematic(telematic) {
        if (telematic?.id) {
            return this.telematicActions.transition.view(telematic);
        }
    }
}
