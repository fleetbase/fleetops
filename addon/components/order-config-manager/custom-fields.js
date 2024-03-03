import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { isArray } from '@ember/array';
import { task } from 'ember-concurrency-decorators';
import isObject from '@fleetbase/ember-core/utils/is-object';

export default class OrderConfigManagerCustomFieldsComponent extends Component {
    @service store;
    @service notifications;
    @service modalsManager;
    @service contextPanel;
    @service intl;
    @tracked groups = [];
    @tracked config;
    @tracked gridSizeOptions = [1, 2, 3, 4, 5];

    constructor(owner, { config }) {
        super(...arguments);
        this.config = config;
        this.loadCustomFields.perform();
    }

    @action selectGridSize(group, size) {
        if (!isObject(group.meta)) {
            group.set('meta', {});
        }
        group.meta.grid_size = size;

        return group.save();
    }

    @action createNewCustomField(group) {
        const customField = this.store.createRecord('custom-field', {
            category_uuid: group.id,
            subject_uuid: this.config.id,
            subject_type: 'order-config',
            required: 0,
            options: [],
        });

        this.addCustomFieldToGroup(customField, group);
        this.editCustomField(customField);
    }

    @action editCustomField(customField) {
        this.contextPanel.focus(customField, 'editing', {
            args: {
                customField,
                onCustomFieldSaved: () => {
                    this.loadCustomFields.perform();
                },
                onPressCancel: () => {
                    this.contextPanel.clear();
                },
            },
        });
    }

    @action deleteCustomField(customField) {
        this.modalsManager.confirm({
            title: this.intl.t('fleet-ops.component.order-config-manager.custom-fields.delete-custom-field-prompt.modal-title'),
            body: this.intl.t('fleet-ops.component.order-config-manager.custom-fields.delete-custom-field-prompt.delete-body-message'),
            acceptButtonText: this.intl.t('fleet-ops.component.order-config-manager.custom-fields.delete-custom-field-prompt.confirm-delete'),
            confirm: () => {
                return customField.destroyRecord();
            },
        });
    }

    @action createNewFieldGroup() {
        const customFieldGroup = this.store.createRecord('category', {
            owner_uuid: this.config.id,
            owner_type: 'order-config',
            for: 'custom_field_group',
        });

        this.modalsManager.show('modals/new-custom-field-group', {
            title: this.intl.t('fleet-ops.component.modals.new-custom-field-group.modal-title'),
            acceptButtonIcon: 'check',
            acceptButtonIconPrefix: 'fas',
            declineButtonIcon: 'times',
            declineButtonIconPrefix: 'fas',
            customFieldGroup,
            confirm: (modal) => {
                if (!customFieldGroup.name) {
                    return;
                }

                modal.startLoading();
                return customFieldGroup
                    .save()
                    .then(() => {
                        this.loadCustomFields.perform();
                    })
                    .catch((error) => {
                        this.notifications.serverError(error);
                    });
            },
        });
    }

    @action deleteCustomFieldGroup(group) {
        this.modalsManager.confirm({
            title: this.intl.t('fleet-ops.component.order-config-manager.custom-fields.delete-custom-field-group-prompt.modal-title'),
            body: this.intl.t('fleet-ops.component.order-config-manager.custom-fields.delete-custom-field-group-prompt.delete-body-message'),
            acceptButtonText: this.intl.t('fleet-ops.component.order-config-manager.custom-fields.delete-custom-field-group-prompt.confirm-delete'),
            confirm: () => {
                return group.destroyRecord();
            },
        });
    }

    @task *loadCustomFields() {
        this.groups = yield this.store.query('category', { owner_uuid: this.config.id, for: 'custom_field_group' });
        this.customFields = yield this.store.query('custom-field', { subject_uuid: this.config.id });
        this.groupCustomFields();
    }

    addCustomFieldToGroup(customField, group) {
        if (!isArray(group.customFields)) {
            group.customFields = [];
        }
        group.set('customFields', [...group.customFields, customField]);
    }

    groupCustomFields() {
        for (let i = 0; i < this.groups.length; i++) {
            const group = this.groups[i];
            group.set(
                'customFields',
                this.customFields.filter((customField) => {
                    return customField.category_uuid === group.id;
                })
            );
        }
    }
}
