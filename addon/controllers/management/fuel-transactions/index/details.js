import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class ManagementFuelTransactionsIndexDetailsController extends Controller {
    @service fetch;
    @service notifications;
    @service hostRouter;

    @tracked vehicleId;
    @tracked orderId;

    get normalizedPayloadJson() {
        return JSON.stringify(this.model?.normalized_payload ?? {}, null, 2);
    }

    get rawPayloadJson() {
        return JSON.stringify(this.model?.raw_payload ?? {}, null, 2);
    }

    @action setVehicleId(event) {
        this.vehicleId = event.target.value;
    }

    @action setOrderId(event) {
        this.orderId = event.target.value;
    }

    @task *matchVehicle() {
        try {
            yield this.fetch.post(`fuel-provider-transactions/${this.model.id}/match-vehicle`, { vehicle: this.vehicleId });
            this.notifications.success('Fuel transaction matched to vehicle.');
            yield this.hostRouter.refresh();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task *matchOrder() {
        try {
            yield this.fetch.post(`fuel-provider-transactions/${this.model.id}/match-order`, { order: this.orderId });
            this.notifications.success('Fuel transaction matched to order.');
            yield this.hostRouter.refresh();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task *reprocess() {
        try {
            yield this.fetch.post(`fuel-provider-transactions/${this.model.id}/reprocess`);
            this.notifications.success('Fuel transaction reprocessed.');
            yield this.hostRouter.refresh();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task *review(status) {
        try {
            yield this.fetch.post(`fuel-provider-transactions/${this.model.id}/review`, { status });
            this.notifications.success(status === 'ignored' ? 'Fuel transaction ignored.' : 'Fuel transaction reviewed.');
            yield this.hostRouter.refresh();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
}
