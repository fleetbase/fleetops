import Controller from '@ember/controller';
import { action, get } from '@ember/object';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';

const severityOptions = [
    { label: 'Info', value: 'info' },
    { label: 'Warning', value: 'warning' },
    { label: 'Error', value: 'error' },
    { label: 'Critical', value: 'critical' },
    { label: 'High', value: 'high' },
];

const processedOptions = [
    { label: 'Processed', value: 'processed' },
    { label: 'Unprocessed', value: 'unprocessed' },
];

function eventDevice(event) {
    const device = get(event, 'device');

    if (device) {
        return device;
    }

    const id = get(event, 'device_uuid');
    const deviceId = get(event, 'device_id') ?? get(event, 'provider_device_id') ?? get(event, 'ident');
    const name = get(event, 'device_name') ?? deviceId;
    const imei = get(event, 'device_imei') ?? get(event, 'imei') ?? get(event, 'ident');

    if (!id && !name && !imei && !deviceId) {
        return null;
    }

    return {
        id,
        displayName: name,
        name,
        imei,
        device_id: deviceId,
        ident: get(event, 'ident'),
        serial_number: get(event, 'device_serial_number'),
        connection_status: get(event, 'device_connection_status'),
        status: get(event, 'device_status'),
        photo_url: get(event, 'device_photo_url') ?? get(event, 'photo_url'),
    };
}

function eventTelematic(event) {
    const telematic = get(event, 'device.telematic') ?? get(event, 'telematic');

    if (telematic) {
        return telematic;
    }

    const id = get(event, 'telematic_uuid');
    const name = get(event, 'telematic_name');
    const provider = get(event, 'provider');

    if (!id && !name && !provider) {
        return null;
    }

    return {
        id,
        name: name ?? provider,
        provider,
        provider_descriptor: get(event, 'provider_descriptor'),
    };
}

export default class ConnectivityEventsIndexController extends Controller {
    @service deviceEventActions;
    @service deviceActions;
    @service hostRouter;
    @service intl;
    @service store;
    @service telematicActions;

    /** query params */
    @tracked queryParams = ['page', 'limit', 'sort', 'query', 'telematic', 'device', 'event_type', 'severity', 'processed', 'occurred_at', 'created_at', 'updated_at'];
    @tracked page = 1;
    @tracked limit;
    @tracked sort = '-created_at';
    @tracked query;
    @tracked telematic;
    @tracked device;
    @tracked event_type;
    @tracked severity;
    @tracked processed;
    @tracked occurred_at;
    @tracked created_at;
    @tracked updated_at;

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
            filterParam: 'event_type',
            filterComponent: 'filter/string',
        },
        {
            label: 'Device',
            valuePath: 'device.displayName',
            cellComponent: 'cell/device-identity',
            resourcePath: eventDevice,
            compact: true,
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
        },
        {
            label: 'Provider',
            valuePath: 'provider',
            cellComponent: 'cell/telematic-provider',
            resourcePath: eventTelematic,
            compact: true,
            action: this.openTelematic,
            permission: 'fleet-ops view telematic',
            resizable: true,
            sortable: true,
            filterable: true,
            filterParam: 'telematic',
            filterComponent: 'filter/model',
            filterComponentPlaceholder: 'Select telematic',
            model: 'telematic',
        },
        {
            label: 'Severity',
            valuePath: 'severity',
            cellComponent: 'table/cell/status',
            resizable: true,
            sortable: true,
            filterable: true,
            filterParam: 'severity',
            filterComponent: 'filter/multi-option',
            filterOptions: severityOptions,
            filterOptionLabel: 'label',
            filterOptionValue: 'value',
        },
        {
            label: 'Message',
            valuePath: 'message',
            resizable: true,
            sortable: false,
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
            label: 'Processed',
            valuePath: 'processedAt',
            sortParam: 'processed_at',
            resizable: true,
            sortable: true,
            filterable: true,
            filterParam: 'processed',
            filterComponent: 'filter/multi-option',
            filterOptions: processedOptions,
            filterOptionLabel: 'label',
            filterOptionValue: 'value',
        },
        {
            label: 'Occurred',
            valuePath: 'occurredAt',
            sortParam: 'occurred_at',
            resizable: true,
            sortable: true,
            filterable: true,
            filterParam: 'occurred_at',
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
            filterable: true,
            filterParam: 'updated_at',
            filterComponent: 'filter/date',
            hidden: true,
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
                {
                    label: 'Mark processed',
                    fn: this.markProcessed,
                    permission: 'fleet-ops update device-event',
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

    @action async markProcessed(deviceEvent) {
        await this.deviceEventActions.markProcessed(deviceEvent);
        await this.hostRouter.refresh();
    }
}
