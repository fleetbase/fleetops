import Controller from '@ember/controller';
import { inject as service } from '@ember/service';

/**
 * Operations::AllocationController
 *
 * Thin controller for the /operations/allocation route.
 * All allocation logic lives in the OrderAllocationWorkbench component
 * and the order-allocation service. This controller exists to satisfy
 * the Ember route/controller convention and to provide the page title.
 */
export default class OperationsAllocationController extends Controller {
    @service intl;

    get pageTitle() {
        return this.intl.t('allocation.page-title');
    }
}
