import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { dasherize, camelize } from '@ember/string';
import { task } from 'ember-concurrency-decorators';
import isObject from '@fleetbase/ember-core/utils/is-object';
import contextComponentCallback from '../utils/context-component-callback';
import applyContextComponentArguments from '../utils/apply-context-component-arguments';

export default class CustomFieldFormPanelComponent extends Component {
    @service notifications;
    @tracked customField;
    @tracked currentFieldMap;
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
        dateTimeInput: {
            component: 'data-time-input',
        },
        radioButton: {
            component: 'radio-button-select',
            hasOptions: true,
        },
        select: {
            component: 'select',
            hasOptions: true,
        },
        modelSelect: {
            allowedModels: ['driver', 'contact', 'vendor', 'place', 'issue', 'fuel-report'],
            component: 'model-select',
        },
        fileUpload: {
            component: 'file-upload',
        },
        dropzone: {
            component: 'file-dropzone',
        },
    };

    constructor() {
        super(...arguments);
        applyContextComponentArguments(this);
        this.selectFieldMap(this.customField.type);
    }

    @task *save() {
        yield this.customField
            .save()
            .then((customField) => {
                contextComponentCallback(this, 'onCustomFieldSaved', customField);
            })
            .catch((error) => {
                this.notifications.serverError(error);
            });
    }

    @action setCustomFieldName(event) {
        const value = event.target.value;
        this.customField.name = dasherize(value);
    }

    @action onSelectCustomFieldType(event) {
        const value = event.target.value;
        const type = dasherize(value);
        this.customField.type = type;
        this.selectFieldMap(type);
    }

    @action onSelectModelType(event) {
        const value = event.target.value;
        const modelName = dasherize(value);
        this.setCustomFieldMetaProperty('modelName', modelName);
    }

    @action setCustomFieldMetaProperty(key, value) {
        if (!isObject(this.customField.meta)) {
            this.customField.set('meta', {});
        }

        this.customField.meta[key] = value;
    }

    selectFieldMap(type) {
        if (!type) {
            return;
        }
        const fieldKey = camelize(type);
        const fieldMap = this.customFieldTypeMap[fieldKey];
        if (fieldMap) {
            this.currentFieldMap = fieldMap;
            this.customField.component = fieldMap.component;
        }
    }
}
