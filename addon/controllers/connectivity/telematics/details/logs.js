import Controller from '@ember/controller';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';

export default class ConnectivityTelematicsDetailsLogsController extends Controller {
    @service hostRouter;

    @tracked telematic;

    get logs() {
        return Array.from(this.model?.logs ?? []);
    }

    get hasLogs() {
        return this.logs.length > 0;
    }

    get warningCount() {
        return this.logs.filter((log) => ['warning', 'danger', 'error'].includes(String(log.status ?? '').toLowerCase())).length;
    }

    get syncCount() {
        return this.logs.filter((log) => String(log.type ?? '').startsWith('sync')).length;
    }

    get testCount() {
        return this.logs.filter((log) => String(log.type ?? '').startsWith('connection_test')).length;
    }

    get metrics() {
        return [
            { label: 'Log entries', value: this.logs.length, icon: 'list', accentClass: 'fleetops-connectivity-kpi-accent-blue' },
            { label: 'Warnings', value: this.warningCount, icon: 'triangle-exclamation', accentClass: 'fleetops-connectivity-kpi-accent-amber' },
            { label: 'Sync records', value: this.syncCount, icon: 'satellite-dish', accentClass: 'fleetops-connectivity-kpi-accent-green' },
            { label: 'Connection tests', value: this.testCount, icon: 'plug', accentClass: 'fleetops-connectivity-kpi-accent-rose' },
        ];
    }

    @action refresh() {
        return this.hostRouter.refresh();
    }
}
