import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { openTransactionAction, purchaseGroups } from '../../../../utils/fuel-transaction';

export default class ManagementFuelTransactionsIndexDetailsController extends Controller {
    @service modalsManager;
    @service hostRouter;

    get purchaseGroups() {
        return purchaseGroups(this.model);
    }

    @action confirmAction(mode) {
        return openTransactionAction(this.modalsManager, mode, this.model);
    }
    @action openFuelReport() {
        if (this.model.fuel_report_id) return this.hostRouter.transitionTo('console.fleet-ops.management.fuel-reports.index.details', this.model.fuel_report_id);
    }
}
