import Controller from '@ember/controller';
import { action, set } from '@ember/object';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';

function firstPresent(...values) {
    return values.find((value) => value !== undefined && value !== null && value !== '');
}

function toDate(value) {
    if (value instanceof Date) {
        return value;
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? value : date;
}

export default class ConnectivityEventsDetailsController extends Controller {
    @service deviceEventActions;
    @service hostRouter;

    @tracked isMarkingProcessed = false;

    get event() {
        return this.model;
    }

    get isProcessed() {
        return Boolean(firstPresent(this.event?.processed_at, this.event?.processedAt));
    }

    get processedStatus() {
        return this.isProcessed ? 'success' : 'warning';
    }

    get processedLabel() {
        return this.isProcessed ? 'Processed' : 'Unprocessed';
    }

    @action goBack() {
        return this.hostRouter.transitionTo('console.fleet-ops.connectivity.events.index');
    }

    @action refresh() {
        return this.hostRouter.refresh();
    }

    @action async markProcessed() {
        if (this.isMarkingProcessed) {
            return;
        }

        this.isMarkingProcessed = true;

        try {
            const response = await this.deviceEventActions.markProcessed(this.event);
            const processedAt = firstPresent(response?.device_event?.processed_at, response?.device_event?.processedAt, new Date());

            if (this.event) {
                set(this.event, 'processed_at', toDate(processedAt));
            }

            return response;
        } finally {
            this.isMarkingProcessed = false;
        }
    }
}
