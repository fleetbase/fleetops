import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { underscore, capitalize, w } from '@ember/string';
import applyContextComponentArguments from '../utils/apply-context-component-arguments';

export default class ActivityFormPanelComponent extends Component {
    @tracked activity;
    @tracked targetActivity;

    constructor() {
        super(...arguments);
        applyContextComponentArguments(this);
    }

    @action setActivityCode(event) {
        const value = event.target.value;
        const code = underscore(value);
        this.activity.set('code', code);
        this.activity.set('status', w(value.replace('_', ' ')).map(capitalize).join(' '));
    }

    @action updateActivityLogic(logic = []) {
        console.log('updateActivityLogic() #logic', logic);
        this.activity.set('logic', logic);
    }

    @action updateActivityEvents(events = []) {
        console.log('updateActivityEvents() #events', events);
        this.activity.set('events', events);
    }
}
