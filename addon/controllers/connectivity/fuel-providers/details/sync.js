import Controller from '@ember/controller';
import { inject as controller } from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { fuelDate } from '../../../../utils/fuel-integration-format';

export default class FuelIntegrationSyncController extends Controller {
    @controller('connectivity.fuel-providers.details') details;
    @tracked from;
    @tracked to;
    get dateError() {
        return this.from && this.to && this.from > this.to ? 'Start date must be on or before end date.' : null;
    }
    get syncDisabled() {
        return this.details.syncDisabled || Boolean(this.dateError) || !this.from || !this.to;
    }
    @action setDate(field, event) {
        this[field] = event.target.value;
    }
    @action resetDates() {
        const from = new Date();
        from.setDate(from.getDate() - Number(this.model?.sync_settings?.window_days || 7));
        this.from = fuelDate(from).slice(0, 10);
        this.to = fuelDate(new Date()).slice(0, 10);
    }
    @action sync() {
        if (!this.syncDisabled) return this.details.syncTransactions.perform({ from: this.from, to: this.to });
    }
}
