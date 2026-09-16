import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import { transactionActions } from '../../utils/fuel-transaction';
import { fuelDate, fuelMoney } from '../../utils/fuel-integration-format';

export default class FuelTransactionActionComponent extends Component {
    @service fetch;
    @service notifications;
    @tracked selected;
    @tracked error;
    @tracked completed = [];

    constructor() {
        super(...arguments);
        this.args.options.keepOpen = true;
        this.args.options.confirm = (_modal, done) => this.save.perform(done);
    }
    get mode() {
        return this.args.options.mode;
    }
    get transactions() {
        return this.args.options.transactions;
    }
    get description() {
        return transactionActions[this.mode].description;
    }
    get isMatching() {
        return ['vehicle', 'order'].includes(this.mode);
    }
    get isVehicle() {
        return this.mode === 'vehicle';
    }
    get selectionLabel() {
        return this.isVehicle ? 'Vehicle' : 'Order / Trip';
    }
    get selectedName() {
        return this.selected?.displayName || this.selected?.public_id || this.selected?.internal_id;
    }
    get preview() {
        return this.transactions.map((record) => ({
            reference: record.provider_transaction_id,
            provider: record.provider,
            status: record.sync_status,
            date: fuelDate(record.transaction_at, 'Date unavailable'),
            amount: fuelMoney(record.amount, record.currency),
            station: record.station_name || 'Station unavailable',
            plate: record.plate_number || 'Not provided',
            vehicle: record.vehicle_name || (record.vehicle_uuid ? 'Linked vehicle' : 'Not matched'),
            order: record.order_uuid ? record.trip_number || 'Linked order' : 'Not matched',
            report: record.fuel_report_id || 'Not created',
        }));
    }
    get modalOptions() {
        return { ...this.args.options, isLoading: this.save.isRunning, acceptButtonDisabled: this.save.isRunning || (this.isMatching && !this.selected?.id) };
    }
    @action select(record) {
        this.selected = record;
    }

    @task({ drop: true }) *save(done) {
        if (this.isMatching && !this.selected?.id) return;
        this.error = null;
        const endpoint = this.isMatching ? `match-${this.mode}` : this.mode === 'reprocess' ? 'reprocess' : 'review';
        const body = this.isMatching ? { [this.mode]: this.selected.id } : this.mode === 'reprocess' ? {} : { status: this.mode };
        for (const transaction of this.transactions) {
            // A bulk retry must not repeat requests that already succeeded.
            if (this.completed.includes(transaction.id)) continue;
            try {
                yield this.fetch.post(`fuel-provider-transactions/${transaction.id}/${endpoint}`, body);
            } catch {
                this.error = `Could not update transaction ${transaction.provider_transaction_id}. ${this.completed.length ? `${this.completed.length} already updated. ` : ''}Retry to continue, or cancel and refresh the ledger to check its current state.`;
                return;
            }
            this.completed = [...this.completed, transaction.id];
            try {
                yield transaction.reload();
            } catch {
                this.notifications.warning('The change was saved, but the displayed transaction could not be refreshed. Refresh the ledger to see its latest state.');
            }
        }
        this.notifications.success(`${this.transactions.length === 1 ? 'Fuel transaction' : `${this.transactions.length} fuel transactions`} updated.`);
        done?.();
        try {
            yield this.args.options.onSaved?.();
        } catch {
            this.notifications.warning('Refresh the ledger to see the latest transactions.');
        }
    }
}
