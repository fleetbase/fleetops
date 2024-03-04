import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import contextComponentCallback from '../utils/context-component-callback';
import getWithDefault from '@fleetbase/ember-core/utils/get-with-default';

export default class ActivityEventSelectorComponent extends Component {
    @tracked events = [];
    availableEvents = {
        'order.dispatched': {
            name: 'order.dispatched',
            description: 'Triggers when an order is successfully dispatched.',
        },
        'order.failed': {
            name: 'order.failed',
            description: 'Triggers when an order fails due to an error or exception.',
        },
        'order.canceled': {
            name: 'order.canceled',
            description: 'Triggers when an order is canceled by a user, driver, or system process.',
        },
    };

    constructor(owner, { activity }) {
        super(...arguments);
        this.events = getWithDefault(activity, 'events', []);
    }

    @action addEvent(event) {
        this.events = [event, ...this.events];
        this.update();
    }

    @action removeEvent(index) {
        this.events = this.events.filter((_, i) => i !== index);
        this.update();
    }

    @action update() {
        contextComponentCallback(this, 'onChange', this.events);
    }
}
