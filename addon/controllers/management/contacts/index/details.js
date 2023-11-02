import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';

export default class ManagementContactsIndexDetailsController extends Controller {
    /**
     * The currently active view tab ('details' by default).
     *
     * @type {String}
     * @tracked
     */
    @tracked view = 'details';

    /**
     * An array of query parameters to be serialized in the URL.
     *
     * @type {String[]}
     * @tracked
     */
    @tracked queryParams = ['view'];

    /**
     * Transitions back to the "management.contacts.index" route.
     *
     * @method
     * @action
     * @returns {Transition} The transition object representing the route change.
     */
    @action transitionBack() {
        return this.transitionToRoute('management.contacts.index');
    }

    /**
     * Transitions to the edit view for a specific vehicle.
     *
     * @method
     * @param {contactModel} contact - The vehicle to be edited.
     * @action
     * @returns {Transition} The transition object representing the route change.
     */
    @action onEdit(contact) {
        return this.transitionToRoute('management.contacts.index.edit', contact);
    }

    /**
     * Updates the active view tab.
     *
     * @method
     * @param {String} tab - The name of the tab to activate.
     * @action
     */
    @action onTabChanged(tab) {
        this.view = tab;
    }
}