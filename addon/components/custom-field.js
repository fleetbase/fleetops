import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import getCustomFieldTypeMap from '../utils/get-custom-field-type-map';

export default class CustomFieldComponent extends Component {
    @tracked orderConfig;
    @tracked customField;
    @tracked order;
    @tracked customFieldComponent;
    @tracked value;

    /**
     * A map defining the available custom field types and their corresponding components.
     */
    customFieldTypeMap = getCustomFieldTypeMap();

    constructor(owner, { customField, orderConfig, order }) {
        super(...arguments);
        this.customField = customField;
        this.orderConfig = orderConfig;
        this.order = order;
        this.customFieldComponent = typeof customField.component === 'string' ? customField.component : 'input';
    }

    @action onChangeHandler(event) {
        const value = event.target.value;
        this.value = value;

        if (typeof this.args.onChange === 'function') {
            this.args.onChange(value, this.customField);
        }
    }
}
