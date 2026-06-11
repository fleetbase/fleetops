import Controller from '@ember/controller';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import fleetOpsOptions from '../../../../utils/fleet-ops-options';

export default class ConnectivityTelematicsDetailsSensorsController extends Controller {
    @service sensorActions;
    @service deviceActions;
    @service hostRouter;
    @service intl;

    @tracked queryParams = ['page', 'limit', 'sort', 'query', 'status', 'type', 'device_uuid'];
    @tracked telematic;
    @tracked page = 1;
    @tracked limit;
    @tracked sort = '-updated_at';
    @tracked query;
    @tracked status;
    @tracked type;
    @tracked device_uuid;

    get sensors() {
        return Array.from(this.model ?? []);
    }

    get activeSensorsCount() {
        return this.sensors.filter((sensor) => sensor.is_active || sensor.status === 'active' || sensor.status === 'online').length;
    }

    get sensorsWithReadingsCount() {
        return this.sensors.filter((sensor) => sensor.last_value || sensor.last_reading_at).length;
    }

    get deviceCount() {
        return new Set(this.sensors.map((sensor) => sensor.device_uuid).filter(Boolean)).size;
    }

    get hasActiveFilters() {
        return Boolean(this.query || this.status || this.type || this.device_uuid);
    }

    get hasSensors() {
        return this.sensors.length > 0;
    }

    get hasSyncedDevices() {
        const meta = this.telematic?.meta ?? {};

        return Number(meta.last_sync_total ?? meta.device_count ?? 0) > 0;
    }

    get hasSyncRun() {
        const meta = this.telematic?.meta ?? {};

        return Boolean(meta.last_sync_started_at || meta.last_sync_completed_at || meta.last_sync_job_id || meta.last_sync_result || meta.last_sync_total);
    }

    get emptyStateVariant() {
        if (this.hasSensors) {
            return null;
        }

        if (this.hasActiveFilters) {
            return 'filtered_empty';
        }

        if (!this.hasSyncedDevices && !this.hasSyncRun) {
            return 'not_synced';
        }

        return 'empty';
    }

    get emptyStateContent() {
        switch (this.emptyStateVariant) {
            case 'filtered_empty':
                return {
                    tone: 'info',
                    icon: 'filter',
                    title: 'No sensors match these filters',
                    message: 'Clear the current search and filters to return to the full sensor inventory.',
                    primaryText: 'Clear filters',
                    primaryIcon: 'filter',
                    primaryAction: this.clearFilters,
                };
            case 'not_synced':
                return {
                    tone: 'warning',
                    icon: 'satellite-dish',
                    title: 'Sync devices before reviewing sensors',
                    message: 'Sensor inventory depends on synced devices and provider telemetry ingestion.',
                    primaryText: 'Go to Devices',
                    primaryIcon: 'microchip',
                    primaryAction: this.goToDevices,
                };
            case 'empty':
                return {
                    tone: 'info',
                    icon: 'gauge-high',
                    title: 'No sensors reported yet',
                    message: 'Devices are synced, but this provider has not reported sensor inventory or webhook sensor data yet.',
                    primaryText: 'Refresh',
                    primaryIcon: 'refresh',
                    primaryAction: this.refresh,
                    secondaryText: 'View Devices',
                    secondaryIcon: 'microchip',
                    secondaryAction: this.goToDevices,
                    note: 'Some providers expose sensors only after live telemetry, fuel, or diagnostic events are ingested.',
                };
            default:
                return null;
        }
    }

    get metrics() {
        return [
            { label: 'Sensors', value: this.model?.meta?.total ?? this.sensors.length, icon: 'gauge-high', accentClass: 'fleetops-connectivity-kpi-accent-blue' },
            { label: 'Active', value: this.activeSensorsCount, icon: 'signal', accentClass: 'fleetops-connectivity-kpi-accent-green' },
            { label: 'Reporting values', value: this.sensorsWithReadingsCount, icon: 'chart-line', accentClass: 'fleetops-connectivity-kpi-accent-amber' },
            { label: 'Parent devices', value: this.deviceCount, icon: 'microchip', accentClass: 'fleetops-connectivity-kpi-accent-blue' },
        ];
    }

    @tracked actionButtons = [
        {
            icon: 'refresh',
            size: 'xs',
            onClick: this.refresh,
            helpText: this.intl.t('common.refresh'),
            wrapperClass: 'fleetops-telematics-action-button',
        },
        {
            icon: 'microchip',
            text: 'View Devices',
            size: 'xs',
            onClick: this.goToDevices,
            wrapperClass: 'fleetops-telematics-action-button',
        },
    ];

    @tracked bulkActions = [];

    get columns() {
        return [
            {
                sticky: true,
                label: this.intl.t('column.name'),
                valuePath: 'name',
                cellComponent: 'table/cell/anchor',
                action: this.sensorActions.transition.view,
                permission: 'fleet-ops view sensor',
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'query',
                filterComponent: 'filter/string',
            },
            {
                label: 'Type',
                valuePath: 'type',
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'type',
                filterComponent: 'filter/multi-option',
                filterOptions: fleetOpsOptions('sensorTypes'),
            },
            {
                label: 'Device',
                valuePath: 'device.displayName',
                cellComponent: 'table/cell/anchor',
                action: this.deviceActions.transition.view,
                permission: 'fleet-ops view device',
                resizable: true,
                sortable: true,
                filterable: true,
                filterComponent: 'filter/model',
                filterComponentPlaceholder: 'Select device',
                filterParam: 'device_uuid',
                model: 'device',
            },
            {
                label: 'Value',
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
            },
            {
                label: 'Last Reading',
                valuePath: 'lastReadingAt',
                sortParam: 'last_reading_at',
                resizable: true,
                sortable: true,
            },
            {
                label: '',
                cellComponent: 'table/cell/dropdown',
                ddButtonText: false,
                ddButtonIcon: 'ellipsis-h',
                ddButtonIconPrefix: 'fas',
                ddMenuLabel: this.intl.t('common.resource-actions', { resource: this.intl.t('resource.sensor') }),
                cellClassNames: 'overflow-visible align-middle',
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
                        label: 'Open parent device',
                        fn: this.openParentDevice,
                        permission: 'fleet-ops view device',
                    },
                ],
                sortable: false,
                filterable: false,
                resizable: false,
                searchable: false,
            },
        ];
    }

    @action refresh() {
        return this.hostRouter.refresh();
    }

    @action clearFilters() {
        this.query = null;
        this.status = null;
        this.type = null;
        this.device_uuid = null;
        this.page = 1;
    }

    @action goToDevices() {
        return this.hostRouter.transitionTo('console.fleet-ops.connectivity.telematics.details.devices', this.telematic);
    }

    @action openParentDevice(sensor) {
        const device = sensor.device;

        if (device?.id) {
            return this.deviceActions.transition.view(device);
        }

        return this.hostRouter.transitionTo('console.fleet-ops.connectivity.telematics.details.devices', this.telematic, {
            queryParams: { device_id: sensor.device_uuid },
        });
    }
}
