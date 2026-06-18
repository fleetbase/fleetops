import Controller from '@ember/controller';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { task, timeout } from 'ember-concurrency';
import fleetOpsOptions from '../../../../../utils/fleet-ops-options';

export default class ConnectivityDevicesIndexDetailsSensorsController extends Controller {
    @service sensorActions;
    @service hostRouter;
    @service intl;

    @tracked queryParams = ['sensors_page', 'sensors_limit', 'sensors_sort', 'sensors_query', 'sensors_status', 'sensors_type', 'sensors_last_reading_at'];
    @tracked device;
    @tracked sensors_page = 1;
    @tracked sensors_limit;
    @tracked sensors_sort = '-updated_at';
    @tracked sensors_query;
    @tracked sensors_status;
    @tracked sensors_type;
    @tracked sensors_last_reading_at;

    @tracked bulkActions = [];

    get sensors() {
        return Array.from(this.model ?? []);
    }

    get totalSensors() {
        return this.model?.meta?.total ?? this.sensors.length;
    }

    get activeSensorsCount() {
        return this.sensors.filter((sensor) => sensor.is_active || sensor.status === 'active' || sensor.status === 'online').length;
    }

    get reportingSensorsCount() {
        return this.sensors.filter((sensor) => sensor.last_value || sensor.last_reading_at).length;
    }

    get outOfRangeSensorsCount() {
        return this.sensors.filter((sensor) => ['out_of_range', 'above_maximum', 'below_minimum'].includes(sensor.threshold_status)).length;
    }

    get hasActiveFilters() {
        return Boolean(this.sensors_query || this.sensors_status || this.sensors_type || this.sensors_last_reading_at);
    }

    get metrics() {
        return [
            { label: 'Sensors', value: this.totalSensors, icon: 'gauge-high', accentClass: 'fleetops-connectivity-kpi-accent-blue' },
            { label: 'Active', value: this.activeSensorsCount, icon: 'signal', accentClass: 'fleetops-connectivity-kpi-accent-green' },
            { label: 'Reporting', value: this.reportingSensorsCount, icon: 'chart-line', accentClass: 'fleetops-connectivity-kpi-accent-amber' },
            {
                label: 'Out of range',
                value: this.outOfRangeSensorsCount,
                icon: 'triangle-exclamation',
                accentClass: this.outOfRangeSensorsCount ? 'fleetops-connectivity-kpi-accent-rose' : 'fleetops-connectivity-kpi-accent-green',
            },
        ];
    }

    get actionButtons() {
        return [
            {
                icon: 'refresh',
                size: 'sm',
                onClick: this.refresh,
                helpText: this.intl.t('common.refresh'),
                wrapperClass: 'fleetops-telematics-action-button',
                isLoading: this.refreshTask.isRunning,
                disabled: this.refreshTask.isRunning,
            },
        ];
    }

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
                filterParam: 'sensors_query',
                filterComponent: 'filter/string',
            },
            {
                label: 'Type',
                valuePath: 'type',
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'sensors_type',
                filterComponent: 'filter/multi-option',
                filterOptions: fleetOpsOptions('sensorTypes'),
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
                label: 'Threshold',
                valuePath: 'threshold_status',
                cellComponent: 'table/cell/status',
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
                filterParam: 'sensors_status',
                filterComponent: 'filter/multi-option',
                filterOptions: fleetOpsOptions('sensorStatuses'),
            },
            {
                label: 'Last Reading',
                valuePath: 'lastReadingAt',
                sortParam: 'last_reading_at',
                resizable: true,
                sortable: true,
                filterable: true,
                filterParam: 'sensors_last_reading_at',
                filterComponent: 'filter/date',
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
                        label: this.intl.t('common.edit-resource', { resource: this.intl.t('resource.sensor') }),
                        fn: this.sensorActions.transition.edit,
                        permission: 'fleet-ops update sensor',
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
        if (this.refreshTask.isRunning) {
            return;
        }

        return this.refreshTask.perform();
    }

    @task *refreshTask() {
        yield this.hostRouter.refresh();
    }

    @task({ restartable: true }) *searchTask(event) {
        const {
            target: { value },
        } = event;

        if (!value) {
            this.sensors_query = null;
            return;
        }

        yield timeout(250);
        if (this.sensors_page > 1) this.sensors_page = 1;
        this.sensors_query = value;
    }
}
