import ResourceActionService from '@fleetbase/ember-core/services/resource-action';
import { action } from '@ember/object';
import { debug } from '@ember/debug';

export default class EntityActionsService extends ResourceActionService {
    constructor() {
        super(...arguments);
        this.initialize('entity', { defaultAttributes: { type: 'entity' } });
    }

    modal = {
        create: (attributes = {}, options = {}, saveOptions = {}) => {
            const entity = this.createNewInstance(attributes);
            return this.modalsManager.show('modals/resource', {
                resource: entity,
                title: this.intl.t('common.create-a-new-resource', { resource: this.intl.t('resource.entity')?.toLowerCase() }),
                acceptButtonText: this.intl.t('common.create-resource', { resource: this.intl.t('resource.entity') }),
                component: 'entity/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', entity, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        edit: (entity, options = {}, saveOptions = {}) => {
            return this.modalsManager.show('modals/resource', {
                resource: entity,
                title: entity.isNew ? 'Create new entity' : `Edit: ${entity.name ?? entity.public_id}`,
                acceptButtonText: this.intl.t('common.save-changes'),
                saveButtonIcon: 'save',
                component: 'entity/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', entity, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        view: (entity, options = {}) => {
            return this.modalsManager.show('modals/resource', {
                resource: entity,
                title: entity.name ?? entity.public_id,
                component: 'entity/details',
                ...options,
            });
        },
    };

    @action async viewLabel(entity) {
        // render dialog to display label within
        this.modalsManager.show(`modals/entity-label`, {
            title: this.intl.t('order.fields.entity-label'),
            modalClass: 'modal-xl',
            acceptButtonText: this.intl.t('common.done'),
            hideDeclineButton: true,
            entity,
        });
        try { 
            // load the pdf label from base64
            // eslint-disable-next-line no-undef 
            const fileReader = new FileReader();
            const { data: pdfStream } = await this.fetch.get(`labels/${entity.public_id}?type=entity&format=base64`);
            // eslint-disable-next-line no-undef
            const base64 = await fetch(`data:application/pdf;base64,${pdfStream}`);
            const blob = await base64.blob();
            // load into file reader
            fileReader.onload = (event) => {
                const data = event.target.result;
                this.modalsManager.setOption('data', data);
            };
            fileReader.readAsDataURL(blob);
        } catch (err) {
            this.notifications.error(this.intl.t('order.prompts.failed-to-load-entity-label'));
            debug('Error loading entity label data: ' + err.message);
        }
    }
}
