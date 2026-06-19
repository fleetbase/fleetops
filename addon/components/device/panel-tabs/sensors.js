import Component from '@glimmer/component';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { task } from 'ember-concurrency';

function toRecentList(records) {
    const list = Array.from(records ?? []);

    list.meta = {
        current_page: 1,
        last_page: 1,
        per_page: list.length,
        total: list.length,
        from: list.length > 0 ? 1 : 0,
        to: list.length,
    };

    return list;
}

export default class DevicePanelTabsSensorsComponent extends Component {
    @service sensorActions;
    @service store;

    @tracked sensors = toRecentList();

    constructor() {
        super(...arguments);
        this.loadSensors.perform();
    }

    get device() {
        return this.args.resource ?? this.args.model;
    }

    get columns() {
        return [
            {
                sticky: true,
                label: 'Sensor',
                valuePath: 'name',
                cellComponent: 'table/cell/anchor',
                action: this.sensorActions.panel?.view ?? this.sensorActions.transition.view,
                permission: 'fleet-ops view sensor',
                resizable: true,
            },
            {
                label: 'Type',
                valuePath: 'type',
                cellComponent: 'table/cell/base',
                humanize: true,
                resizable: true,
            },
            {
                label: 'Value',
                valuePath: 'last_value',
                resizable: true,
            },
            {
                label: 'Unit',
                valuePath: 'unit',
                resizable: true,
            },
            {
                label: 'Status',
                valuePath: 'status',
                cellComponent: 'table/cell/status',
                resizable: true,
            },
            {
                label: 'Last Reading',
                valuePath: 'lastReadingAt',
                sortParam: 'last_reading_at',
                resizable: true,
            },
        ];
    }

    @action refreshSensors() {
        return this.loadSensors.perform();
    }

    @task *loadSensors() {
        if (!this.device?.id) {
            this.sensors = toRecentList();
            return;
        }

        const sensors = yield this.store.query('sensor', { device_uuid: this.device.id, limit: 10, sort: '-updated_at' });
        this.sensors = toRecentList(sensors);
    }
}
