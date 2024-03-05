import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { dasherize } from '@ember/string';
import contextComponentCallback from '../utils/context-component-callback';
import applyContextComponentArguments from '../utils/apply-context-component-arguments';

export default class CustomEntityFormPanelComponent extends Component {
    @service notifications;
    @tracked customEntity;

    constructor() {
        super(...arguments);
        applyContextComponentArguments(this);
    }

    @action save() {
        contextComponentCallback(this, 'onSave', this.customEntity);
        if (typeof this.onSave === 'function') {
            this.onSave(this.customEntity);
        }
    }

    @action setCustomEntityType(event) {
        const value = event.target.value;
        this.customEntity.set('type', dasherize(value));
    }

    @action updateCustomEntityDimensionsUnit(unit) {
        this.customEntity.set('dimensions_unit', unit);
    }

    @action updateCustomEntityWeightUnit(unit) {
        this.customEntity.set('weight_unit', unit);
    }
}
