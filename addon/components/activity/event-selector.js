import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import getWithDefault from '@fleetbase/ember-core/utils/get-with-default';

export default class ActivityEventSelectorComponent extends Component {
    @service intl;

    /**
     * The list of events currently selected for the activity.
     * This is a tracked property, and changes to it will update the component's template.
     *
     * @type {Array}
     */
    @tracked events = [];

    /**
     * An object representing available events, each with a name and description.
     * This property defines the events that can be selected for an activity.
     *
     * @type {Object}
     */
    get availableEvents() {
        return {
            'order.dispatched': {
                name: 'order.dispatched',
                description: this.intl?.t?.('activity.form.event-selector.events.order.dispatched') ?? 'Triggers when an order is successfully dispatched.',
            },
            'order.failed': {
                name: 'order.failed',
                description: this.intl?.t?.('activity.form.event-selector.events.order.failed') ?? 'Triggers when an order fails due to an error or exception.',
            },
            'order.canceled': {
                name: 'order.canceled',
                description: this.intl?.t?.('activity.form.event-selector.events.order.canceled') ?? 'Triggers when an order is canceled by a user, driver, or system process.',
            },
            'order.completed': {
                name: 'order.completed',
                description: this.intl?.t?.('activity.form.event-selector.events.order.completed') ?? 'Triggers when an order is completed by a driver, or system process.',
            },
        };
    }

    /**
     * Constructor for the component. Initializes the component's tracked events.
     * @param {Object} owner - The owner of the component.
     * @param {Object} args - Arguments passed to the component, should include `activity`.
     */
    constructor(owner, { activity }) {
        super(...arguments);
        this.events = getWithDefault(activity, 'events', []);
    }

    /**
     * Adds a new event to the list of tracked events.
     * @param {Object} event - The event to add.
     */
    @action addEvent(event) {
        this.events = [event, ...this.events];
        this.update();
    }

    /**
     * Removes an event from the list of tracked events.
     * @param {number} index - The index of the event to remove.
     */
    @action removeEvent(index) {
        this.events = this.events.filter((_, i) => i !== index);
        this.update();
    }

    /**
     * Triggers the contextComponentCallback with the updated list of events.
     * This method is typically used to inform a parent component or context of the changes.
     */
    @action update() {
        if (typeof this.args.onChange === 'function') {
            this.args.onChange(this.events);
        }
    }
}
