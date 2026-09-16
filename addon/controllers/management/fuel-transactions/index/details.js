import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { openTransactionAction, purchaseGroups } from '../../../../utils/fuel-transaction';
import { buildIdentityStub } from '../../../../utils/identity-cell-resource';
import relationValue from '../../../../utils/relation-value';

export default class ManagementFuelTransactionsIndexDetailsController extends Controller {
    @service modalsManager;
    @service hostRouter;

    get purchaseGroups() {
        return purchaseGroups(this.model);
    }

    /**
     * The linked records as the pills want them: the loaded relation when the
     * store has it, otherwise a stub that carries the name the transaction
     * knows and loads the record on hover or click.
     */
    get vehicleResource() {
        const transaction = this.model;

        if (!transaction?.vehicle_uuid && !transaction?.vehicle_name) {
            return null;
        }

        return (
            relationValue(transaction, 'vehicle') ??
            buildIdentityStub(transaction, { type: 'vehicle', name: transaction.vehicle_name ?? 'Linked vehicle', load: () => transaction.get('vehicle') })
        );
    }

    get orderResource() {
        const transaction = this.model;

        if (!transaction?.order_uuid) {
            return null;
        }

        return (
            relationValue(transaction, 'order') ?? buildIdentityStub(transaction, { type: 'order', name: transaction.trip_number ?? 'Linked order', load: () => transaction.get('order') })
        );
    }

    get fuelReportResource() {
        const transaction = this.model;

        if (!transaction?.fuel_report_id) {
            return null;
        }

        return (
            relationValue(transaction, 'fuel_report') ?? buildIdentityStub(transaction, { type: 'fuel-report', name: transaction.fuel_report_id, load: () => transaction.get('fuel_report') })
        );
    }

    @action confirmAction(mode) {
        return openTransactionAction(this.modalsManager, mode, this.model);
    }
    @action openFuelReport() {
        if (this.model.fuel_report_id) return this.hostRouter.transitionTo('console.fleet-ops.management.fuel-reports.index.details', this.model.fuel_report_id);
    }
}
