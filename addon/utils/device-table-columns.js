const connectionStatusOptions = [
    { label: 'Online', value: 'online' },
    { label: 'Recently Offline', value: 'recently_offline' },
    { label: 'Offline', value: 'offline' },
    { label: 'Long Offline', value: 'long_offline' },
    { label: 'Never Connected', value: 'never_connected' },
];

const attachmentStateOptions = [
    { label: 'Attached', value: 'attached' },
    { label: 'Unattached', value: 'unattached' },
];

export { connectionStatusOptions, attachmentStateOptions };

export default function buildDeviceTableColumns(controller, options = {}) {
    const { showProvider = true, showActions = true, deviceActionMode = 'route', showDeviceStatus = true } = options;
    const viewDevice =
        deviceActionMode === 'panel'
            ? (controller.deviceActions.panel?.view ?? controller.deviceActions.transition?.view)
            : (controller.deviceActions.transition?.view ?? controller.deviceActions.panel?.view);
    const editDevice =
        deviceActionMode === 'panel'
            ? (controller.deviceActions.panel?.edit ?? controller.deviceActions.transition?.edit)
            : (controller.deviceActions.transition?.edit ?? controller.deviceActions.panel?.edit);

    const columns = [
        {
            sticky: true,
            label: 'Telematic Device',
            valuePath: 'displayName',
            cellComponent: 'cell/device-identity',
            action: viewDevice,
            compact: true,
            permission: 'fleet-ops view device',
            resizable: true,
            sortable: true,
            filterable: true,
            filterParam: 'query',
            filterComponent: 'filter/string',
            showStatus: showDeviceStatus,
        },
        {
            label: 'Connection',
            valuePath: 'connection_status',
            cellComponent: 'table/cell/status',
            resizable: true,
            sortable: false,
            filterable: true,
            filterParam: 'connection_status',
            filterComponent: 'filter/multi-option',
            filterOptions: connectionStatusOptions,
            filterOptionLabel: 'label',
            filterOptionValue: 'value',
        },
    ];
    const providerIdColumn = {
        label: 'Provider ID / IMEI',
        valuePath: 'device_id',
        resizable: true,
        sortable: true,
        filterable: true,
        filterParam: 'device_id',
        filterComponent: 'filter/string',
    };
    const vehicleColumn = {
        label: 'Vehicle',
        valuePath: 'attached_to_name',
        cellComponent: 'cell/vehicle-identity',
        action: async (vehicle) => {
            const resolvedVehicle = vehicle?.loadResource ? ((await vehicle.loadResource()) ?? vehicle) : vehicle;

            if (resolvedVehicle?.id && controller.vehicleActions?.panel?.view) {
                return controller.vehicleActions.panel.view(resolvedVehicle);
            }
        },
        compact: true,
        permission: 'fleet-ops view vehicle',
        showStatusBadge: true,
        emptyText: '-',
        resourcePath: (device) => {
            const attachableType = `${device?.attachable_type ?? ''}`.toLowerCase();

            if (!device?.attachable_uuid || (attachableType && !attachableType.includes('vehicle'))) {
                return null;
            }

            return (
                device.attachable ?? {
                    id: device.attachable_uuid,
                    displayName: device.attached_to_name,
                    display_name: device.attached_to_name,
                    name: device.attached_to_name,
                    public_id: device.attachable_uuid,
                    vehicle_number: device.plate_number ?? device.call_sign ?? device.vehicle_number ?? device.attachable_uuid,
                    loadResource: () => controller.resolveAttachedVehicle?.(device),
                }
            );
        },
        resizable: true,
        sortable: false,
        filterable: true,
        filterParam: 'vehicle',
        filterComponent: 'filter/model',
        filterComponentPlaceholder: 'Select vehicle',
        model: 'vehicle',
        modelNamePath: 'displayName',
    };

    if (showProvider) {
        columns.push({
            label: 'Telematic Provider',
            valuePath: 'telematic_name',
            cellComponent: 'cell/telematic-provider',
            compact: true,
            action: controller.openTelematic,
            permission: 'fleet-ops view telematic',
            resizable: true,
            sortable: false,
            filterable: true,
            filterParam: 'telematic',
            filterComponent: 'filter/model',
            filterComponentPlaceholder: 'Select telematic',
            model: 'telematic',
        });
        columns.push(vehicleColumn, providerIdColumn);
    } else {
        columns.push(providerIdColumn, vehicleColumn);
    }

    columns.push(
        {
            label: 'Sensors',
            valuePath: 'sensors_count',
            resizable: true,
            sortable: false,
            filterable: false,
        },
        {
            label: 'Last Seen',
            valuePath: 'lastOnlineAt',
            sortParam: 'last_online_at',
            resizable: true,
            sortable: true,
            filterable: true,
            filterParam: 'last_online_at',
            filterComponent: 'filter/date',
        },
        {
            label: 'Provider',
            valuePath: 'provider',
            hidden: true,
            resizable: true,
            sortable: true,
            filterable: true,
            filterParam: 'provider',
            filterComponent: 'filter/string',
        },
        {
            label: 'Type',
            valuePath: 'type',
            hidden: true,
            resizable: true,
            sortable: true,
            filterable: true,
            filterParam: 'type',
            filterComponent: 'filter/multi-option',
            filterOptions: controller.deviceTypeOptions,
        },
        {
            label: 'Serial Number',
            valuePath: 'serial_number',
            hidden: true,
            resizable: true,
            sortable: true,
            filterable: true,
            filterParam: 'serial_number',
            filterComponent: 'filter/string',
        },
        {
            label: controller.intl.t('column.status'),
            valuePath: 'status',
            cellComponent: 'table/cell/status',
            hidden: true,
            resizable: true,
            sortable: true,
            filterable: true,
            filterParam: 'status',
            filterComponent: 'filter/multi-option',
            filterOptions: controller.deviceStatusOptions,
        },
        {
            label: 'Attachment',
            valuePath: 'attachable_uuid',
            hidden: true,
            resizable: true,
            sortable: false,
            filterable: true,
            filterParam: 'attachment_state',
            filterComponent: 'filter/select',
            filterOptions: attachmentStateOptions,
            filterOptionLabel: 'label',
            filterOptionValue: 'value',
        },
        {
            label: controller.intl.t('column.updated-at'),
            valuePath: 'updatedAt',
            sortParam: 'updated_at',
            resizable: true,
            sortable: true,
            hidden: true,
            filterable: true,
            filterComponent: 'filter/date',
        }
    );

    if (showActions) {
        columns.push({
            label: '',
            cellComponent: 'table/cell/dropdown',
            ddButtonText: false,
            ddButtonIcon: 'ellipsis-h',
            ddButtonIconPrefix: 'fas',
            ddMenuLabel: controller.intl.t('common.resource-actions', { resource: controller.intl.t('resource.device') }),
            cellClassNames: 'overflow-visible align-middle',
            wrapperClass: 'flex items-center justify-end mx-2',
            sticky: 'right',
            width: 60,
            actions: [
                {
                    label: controller.intl.t('common.view-resource', { resource: controller.intl.t('resource.device') }),
                    fn: viewDevice,
                    permission: 'fleet-ops view device',
                },
                {
                    label: controller.intl.t('common.edit-resource', { resource: controller.intl.t('resource.device') }),
                    fn: editDevice,
                    permission: 'fleet-ops update device',
                },
                {
                    label: 'Attach or change vehicle',
                    fn: controller.openAttachDeviceModal ?? controller.deviceActions.attachToVehicle,
                    permission: 'fleet-ops update device',
                },
                {
                    label: controller.intl.t('device.actions.detach-from-vehicle'),
                    fn: controller.deviceActions.detachFromVehicle,
                    permission: 'fleet-ops update device',
                    isVisible: controller.hasAttachedVehicle,
                },
                {
                    separator: true,
                    isVisible: controller.hasAttachedVehicle,
                },
                {
                    label: 'View attached vehicle',
                    fn: controller.viewAttachedVehicle,
                    permission: 'fleet-ops view vehicle',
                    isVisible: controller.hasAttachedVehicle,
                },
                {
                    label: 'Locate attached vehicle on map',
                    fn: controller.locateAttachedVehicle,
                    permission: 'fleet-ops view vehicle',
                    isVisible: controller.hasAttachedVehicle,
                },
                ...(controller.openDeviceEvents
                    ? [
                          {
                              separator: true,
                          },
                          {
                              label: 'Review recent events',
                              fn: controller.openDeviceEvents,
                              permission: 'fleet-ops view device-event',
                          },
                      ]
                    : []),
            ],
            sortable: false,
            filterable: false,
            resizable: false,
            searchable: false,
        });
    }

    return columns;
}
