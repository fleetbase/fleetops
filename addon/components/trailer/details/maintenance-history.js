import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class TrailerDetailsMaintenanceHistoryComponent extends Component {
    @service maintenanceActions;
    @service notifications;
    @service store;
    @service trailerActions;
    @tracked maintenanceHistory = [];

    get trailer() {
        return this.args.resource;
    }

    constructor() {
        super(...arguments);
        this.loadMaintenanceHistory.perform();
    }

    @task *loadMaintenanceHistory() {
        try {
            const records = yield this.store.query('maintenance', {
                maintainable_uuid: this.trailer.id,
                maintainable_type: 'trailer',
                sort: '-created_at',
            });

            this.maintenanceHistory = Array.from(records ?? []);
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action logMaintenance() {
        return this.trailerActions.logMaintenance(this.trailer, {}, { refresh: false, callback: () => this.loadMaintenanceHistory.perform() });
    }
}
