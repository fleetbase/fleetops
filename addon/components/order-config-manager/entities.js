import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { isArray } from '@ember/array';
import { task } from 'ember-concurrency-decorators';
import ObjectProxy from '@ember/object/proxy';
import createCustomEntity from '../../utils/create-custom-entity';
import getWithDefault from '@fleetbase/ember-core/utils/get-with-default';

export default class OrderConfigManagerEntitiesComponent extends Component {
    @service contextPanel;
    @service intl;
    @tracked config;
    @tracked customEntities = [];

    constructor(owner, { config }) {
        super(...arguments);
        this.config = config;
        this.customEntities = this.getEntitiesFromConfig(config);
    }

    @action createNewCustomEntity() {
        const customEntity = createCustomEntity();
        return this.editCustomEntity(customEntity);
    }

    @action editCustomEntity(customEntity, index) {
        this.contextPanel.focus(customEntity, 'editing', {
            args: {
                onPressCancel: () => {
                    this.contextPanel.clear();
                },
                onSave: (customEntity) => {
                    if (index > -1) {
                        this.customEntities = this.customEntities.map((_, i) => {
                            if (i === index) {
                                return customEntity;
                            }

                            return _;
                        });
                    } else {
                        this.customEntities = [customEntity, ...this.customEntities];
                    }
                    this.contextPanel.clear();
                    this.save.perform();
                },
            },
        });
    }

    @action deleteCustomEntity(customEntity, index) {
        this.modalsManager.confirm({
            title: this.intl.t('fleet-ops.component.order-config-manager.custom-fields.delete-custom-field-prompt.modal-title'),
            body: this.intl.t('fleet-ops.component.order-config-manager.custom-fields.delete-custom-field-prompt.delete-body-message'),
            acceptButtonText: this.intl.t('fleet-ops.component.order-config-manager.custom-fields.delete-custom-field-prompt.confirm-delete'),
            confirm: () => {
                this.customEntities = this.customEntities.filter((_, i) => i !== index);
                this.save.perform();
            },
        });
    }

    @task *save() {
        this.config.set('entities', this.serializeEntities());
        yield this.config.save();
    }

    deserializeEntities(customEntities) {
        return customEntities.map(this.fixInternalModel).map((customEntity) => {
            if (customEntity instanceof ObjectProxy) {
                return customEntity;
            }

            return createCustomEntity(customEntity.name, customEntity.type, customEntity.description, { ...customEntity });
        });
    }

    fixInternalModel(customEntity) {
        const _internalModel = {
            modelName: 'custom-entity',
        };
        if (customEntity instanceof ObjectProxy) {
            customEntity.set('_internalModel', _internalModel);
            return customEntity;
        }

        customEntity._internalModel = _internalModel;
        return customEntity;
    }

    serializeEntities() {
        const customEntities = [...this.customEntities];
        return customEntities.map((customEntity) => customEntity.content);
    }

    getEntitiesFromConfig(config) {
        const entities = getWithDefault(config, 'entities', []);
        return this.deserializeEntities(isArray(entities) ? entities : []);
    }
}
