import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { isArray } from '@ember/array';
import { task } from 'ember-concurrency-decorators';

export default class NavigatorAppControlsComponent extends Component {
    @service fetch;
    @tracked isLoading = false;
    @tracked url;
    @tracked selectedOrderConfig;
    @tracked entityFields = ['name', 'description', 'sku', 'height', 'width', 'length', 'weight', 'declared_value', 'sale_price'];
    @tracked entityEditingSettings = {};
    @tracked isEntityFieldsEditable = false;

    constructor() {
        super(...arguments);
        this.getAppLinkUrl.perform();
        this.getEntityEditableSettings.perform();
    }

    @action enableEditableEntityFields(isEntityFieldsEditable) {
        this.isEntityFieldsEditable = isEntityFieldsEditable;
    }

    @action onConfigChanged(orderConfig) {
        this.selectedOrderConfig = orderConfig;
    }

    @action toggleFieldEditable(fieldName, isEditable) {
        const editableFields = this.entityEditingSettings[this.selectedOrderConfig.id]?.editable_entity_fields;
        if (isArray(editableFields)) {
            if (isEditable) {
                editableFields.pushObject(fieldName);
            } else {
                editableFields.removeObject(fieldName);
            }
        } else {
            this.entityEditingSettings = {
                ...this.entityEditingSettings,
                [this.selectedOrderConfig.id]: {
                    editable_entity_fields: [],
                },
            };
            return this.toggleFieldEditable(...arguments);
        }

        this.updateEditableEntityFieldsForOrderConfig(editableFields);
    }

    updateEditableEntityFieldsForOrderConfig(editableFields = [], orderConfig = null) {
        orderConfig = orderConfig === null ? this.selectedOrderConfig : orderConfig;
        this.entityEditingSettings = {
            ...this.entityEditingSettings,
            [orderConfig.id]: {
                editable_entity_fields: editableFields,
            },
        };
    }

    @task *getAppLinkUrl() {
        const response = yield this.fetch.get('fleet-ops/navigator/get-link-app');
        const { linkUrl } = response;
        if (linkUrl) {
            this.url = linkUrl;
        }
    }

    @task *getEntityEditableSettings() {
        const { entityEditingSettings, isEntityFieldsEditable } = yield this.fetch.get('fleet-ops/settings/entity-editing-settings');
        this.entityEditingSettings = entityEditingSettings;
        this.isEntityFieldsEditable = isEntityFieldsEditable;
    }

    @task *saveEntityEditingSettings() {
        const { entityEditingSettings, isEntityFieldsEditable } = this;
        yield this.fetch.post('fleet-ops/settings/entity-editing-settings', { entityEditingSettings, isEntityFieldsEditable });
    }
}
