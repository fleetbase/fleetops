import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { underscore, capitalize, w } from '@ember/string';
import contextComponentCallback from '../utils/context-component-callback';
import applyContextComponentArguments from '../utils/apply-context-component-arguments';

export default class ActivityFormPanelComponent extends Component {
    @tracked activity;
    @tracked targetActivity;

    constructor() {
        super(...arguments);
        applyContextComponentArguments(this);
    }

    @action save() {
        contextComponentCallback(this, 'onSave', this.customEntity);
        if (typeof this.onSave === 'function') {
            this.onSave(this.activity);
        }
    }

    @action setActivityCode(event) {
        const value = event.target.value;
        const code = underscore(value);
        this.activity.set('code', code);
        this.activity.set('status', w(value.replace(/_/g, ' ')).map(capitalize).join(' '));
    }

    @action setActivityKey(event) {
        const value = event.target.value;
        const key = underscore(value);
        this.activity.set('key', key);
    }

    @action updateActivityLogic(logic = []) {
        this.activity.set('logic', logic);
    }

    @action updateActivityEvents(events = []) {
        this.activity.set('events', events);
    }
}
