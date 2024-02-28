import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';

export default class OperationsOrderConfigController extends Controller {
    @tracked tab = 'details';
    queryParams = ['tab'];

    /**
     * Handle tab change.
     *
     * @param {string} tab
     * @memberof OperationsOrderConfigController
     */
    @action onTabChanged(tab) {
        this.tab = tab;
    }
}
