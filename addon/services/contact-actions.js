import ResourceActionService from '@fleetbase/ember-core/services/resource-action';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class ContactActionsService extends ResourceActionService {
    @service placeActions;

    constructor() {
        super(...arguments);
        this.initialize('contact', { defaultAttributes: { type: 'contact', status: 'active' } });
    }

    transition = {
        view: (contact) => this.transitionTo('management.contacts.index.details', contact),
        edit: (contact) => this.transitionTo('management.contacts.index.edit', contact),
        create: () => this.transitionTo('management.contacts.index.new'),
    };

    panel = {
        create: (attributes = {}, options = {}) => {
            const contact = this.createNewInstance(attributes);
            return this.resourceContextPanel.open({
                content: 'contact/form',
                title: this.intl.t('common.create-a-new-resource', { resource: this.intl.t('resource.contact')?.toLowerCase() }),
                saveOptions: {
                    callback: this.refresh,
                },
                useDefaultSaveTask: true,
                contact,
                ...options,
            });
        },
        edit: (contact, options = {}) => {
            return this.resourceContextPanel.open({
                content: 'contact/form',
                title: this.intl.t('common.edit-resource-name', { resourceName: contact.name }),
                contact,
                useDefaultSaveTask: true,
                ...options,
            });
        },
        view: (contact, options = {}) => {
            return this.resourceContextPanel.open({
                contact,
                tabs: [
                    {
                        label: this.intl.t('common.overview'),
                        component: 'contact/details',
                    },
                ],
                ...options,
            });
        },
    };

    modal = {
        create: (attributes = {}, options = {}, saveOptions = {}) => {
            const contact = this.createNewInstance(attributes);
            return this.modalsManager.show('modals/resource', {
                resource: contact,
                title: this.intl.t('common.create-a-new-resource', { resource: this.intl.t('resource.contact')?.toLowerCase() }),
                acceptButtonText: this.intl.t('common.create-resource', { resource: this.intl.t('resource.contact') }),
                component: 'contact/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', contact, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        edit: (contact, options = {}, saveOptions = {}) => {
            return this.modalsManager.show('modals/resource', {
                resource: contact,
                title: this.intl.t('common.edit-resource-name', { resourceName: contact.name }),
                acceptButtonText: this.intl.t('common.save-changes'),
                saveButtonIcon: 'save',
                component: 'contact/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', contact, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        view: (contact, options = {}) => {
            return this.modalsManager.show('modals/resource', {
                resource: contact,
                title: contact.name,
                component: 'contact/details',
                ...options,
            });
        },
    };

    @action async viewPlace(contact) {
        const place = await contact.place;
        if (place) {
            this.placeActions.modal.view(place);
        }
    }

    @action async editPlace(contact) {
        const place = await contact.place;
        if (place) {
            this.placeActions.modal.edit(place);
        }
    }

    @action async createPlace(contact) {
        return this.placeActions.modal.create(
            {},
            {},
            {
                callback: async (place) => {
                    contact.set('place_uuid', place.id);
                    await contact.save();
                },
            }
        );
    }
}
