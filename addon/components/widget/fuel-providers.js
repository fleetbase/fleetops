import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';

export default class WidgetFuelProvidersComponent extends Component {
    static widgetId = 'fleet-ops-fuel-providers-widget';

    @service fetch;

    @tracked data = null;
    @tracked error = null;

    constructor() {
        super(...arguments);
        this.load.perform();
    }

    @task *load() {
        try {
            this.data = yield this.fetch.get('fleet-ops/analytics/fuel-providers', { period: '30d' });
            this.error = null;
        } catch (error) {
            this.error = error?.message ?? 'Failed to load fuel provider data';
        }
    }
}
