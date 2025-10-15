import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { getOwner } from '@ember/application';
import getCurrentNestedController from '@fleetbase/ember-core/utils/get-current-nested-controller';

export default class ManagementContactsController extends Controller {
    @service hostRouter;
    @tracked tabs = [
        {
            route: 'management.contacts.index',
            label: 'Contacts',
        },
        {
            route: 'management.contacts.customers',
            label: 'Customers',
        },
    ];

    get childController() {
        return getCurrentNestedController(getOwner(this), this.hostRouter.currentRouteName);
    }
}
