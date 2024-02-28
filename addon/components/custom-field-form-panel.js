import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { dasherize, camelize } from '@ember/string';
import contextComponentCallback from '../utils/context-component-callback';
import applyContextComponentArguments from '../utils/apply-context-component-arguments';

export default class CustomFieldFormPanelComponent extends Component {
    @service notifications;
    @tracked customField;
    customFieldTypeMap = {
        input: {
            component: 'input',
        },
        phoneInput: {
            component: 'phone-input',
        },
        moneyInput: {
            component: 'money-input',
        },
        select: {
            component: 'select',
        },
        fileUpload: {
            component: 'file-upload',
        },
        dropzone: {
            component: 'file-dropzone',
        },
        modelSelect: {
            allowedModels: ['driver', 'contact', 'vendor', 'place', 'issue', 'fuel-report'],
            component: 'model-select',
        },
    };

    constructor() {
        super(...arguments);
        applyContextComponentArguments(this);
    }

    @action save() {
        return this.customField
            .save()
            .then((customField) => {
                contextComponentCallback(this, 'onCustomFieldSaved', customField);
            })
            .catch((error) => {
                this.notifications.serverError(error);
            });
    }

    @action onPressCancel() {
        contextComponentCallback(this, 'onPressCancel', this.customField);
    }

    @action setCustomFieldName(event) {
        const {
            target: { value },
        } = event;
        this.customField.name = dasherize(value);
    }

    @action onSelectCustomFieldType(event) {
        const {
            target: { value },
        } = event;
        this.customField.type = dasherize(value);

        // set the component from the type
        const fieldKey = camelize(value);
        const fieldMap = this.customFieldTypeMap[fieldKey];
        if (fieldMap) {
            this.customField.component = fieldMap.component;
        }
    }
}
