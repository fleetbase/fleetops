import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { underscore, capitalize, w } from '@ember/string';
import { task } from 'ember-concurrency';
import { inject as service } from '@ember/service';

/**
 * A step of an order config's flow, in its side panel: Details (status, code, details, whether it
 * completes the order and needs proof of delivery), What happens here (the triggers that run when
 * an order reaches it) and Conditions (logic that must pass to reach it).
 *
 * The panel passes options on its overlay: `readonlyDetails` for system configs and steps that
 * can't change, `actions` and `placeholders` from capabilities, and `initialTab`.
 */
export default class ActivityFormComponent extends Component {
    @service abilities;
    @service intl;

    @tracked tab;

    constructor() {
        super(...arguments);
        this.tab = this.args.overlay?.initialTab ?? 'details';
    }

    get canUpdate() {
        return this.abilities.can('fleet-ops update order-config');
    }

    get readonlyDetails() {
        return !this.canUpdate || Boolean(this.args.overlay?.readonlyDetails);
    }

    get triggers() {
        return this.args.resource?.get?.('actions') ?? this.args.resource?.actions ?? [];
    }

    get tabs() {
        const tab = (key, label) => ({ key, label, selected: this.tab === key });
        return [
            tab('details', this.intl.t('order-config-manager.step.tab-details')),
            tab('happens', this.intl.t('order-config-manager.step.tab-happens', { count: this.triggers.length })),
            tab('conditions', this.intl.t('order-config-manager.step.tab-conditions')),
        ];
    }

    @action selectTab(key) {
        this.tab = key;
    }

    @action setTriggers(triggers) {
        this.args.resource.set('actions', triggers);
    }

    @action toggle(key, value) {
        this.args.resource.set(key, value);
    }

    /**
     * Proof of delivery options.
     *
     * @memberof ActivityFormComponent
     */
    @tracked podOptions = ['scan', 'signature', 'photo'];

    /**
     * Task to save the activity. It triggers an optional onSave callback
     * with the current state of the activity.
     * @task
     */
    @task *save() {
        if (typeof this.onSave === 'function') {
            yield this.onSave(this.args.resource);
        }
    }

    /**
     * Sets the proof of delivery method to be used for this activity.
     *
     * @param {Event} event
     * @memberof ActivityFormComponent
     */
    @action setProofOfDeliveryMethod(event) {
        const value = event.target.value;
        this.args.resource.set('pod_method', value);
    }

    /**
     * Action method to set the activity code. It uses the underscore function to format
     * the code and updates the status by capitalizing each word.
     * @param {Event} event - The event object containing the new activity code.
     * @action
     */
    @action setActivityCode(event) {
        const value = event.target.value;
        const code = underscore(value);
        this.args.resource.set('code', code);
        this.args.resource.set('status', w(value.replace(/_/g, ' ')).map(capitalize).join(' '));
    }

    /**
     * Action method to set the activity key. It converts the key to an underscored string.
     * @param {Event} event - The event object containing the new activity key.
     * @action
     */
    @action setActivityKey(event) {
        const value = event.target.value;
        const key = underscore(value);
        this.args.resource.set('key', key);
    }

    /**
     * Action method to update the logic associated with the activity.
     * @param {Array} logic - An array representing the activity logic.
     * @action
     */
    @action updateActivityLogic(logic = []) {
        this.args.resource.set('logic', logic);
    }

    /**
     * Action method to update the events associated with the activity.
     * @param {Array} events - An array of events linked to the activity.
     * @action
     */
    @action updateActivityEvents(events = []) {
        this.args.resource.set('events', events);
    }
}
