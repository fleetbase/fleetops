import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { getOwner } from '@ember/application';
import { task } from 'ember-concurrency';
import config from '../../config/environment';

/**
 * "Radar · 3 overdue": the dashboard tile that links to the page.
 */
export default class WidgetRadarComponent extends Component {
    @service fetch;

    @tracked summary = null;
    @tracked error = null;

    constructor() {
        super(...arguments);
        this.load.perform();
    }

    get stats() {
        return this.summary?.summary ?? { open: 0, overdue: 0, snoozed: 0, critical: 0 };
    }

    get radarRoute() {
        // Dashboard widgets render under the host owner, outside the engine's routing context.
        return getOwner(this).mountPoint ? 'management.index' : `${config.mountedEngineRoutePrefix}.management.index`;
    }

    get accentClass() {
        if (this.stats.critical > 0 || this.stats.overdue > 0) {
            return 'kpi-accent-bad';
        }

        return this.stats.open > 0 ? 'kpi-accent-neutral' : 'kpi-accent-good';
    }

    get valueClass() {
        return this.stats.overdue > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-gray-900 dark:text-white';
    }

    @task({ restartable: true }) *load() {
        this.error = null;
        try {
            this.summary = yield this.fetch.get('fleet-ops/radar/summary');
        } catch (err) {
            this.error = err?.message ?? 'Failed to load';
        }
    }
}
