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
                title: 'Create a new contact',
                panelContentClass: 'px-4',
                saveOptions: {
                    callback: this.refresh,
                },
                contact,
                ...options,
            });
        },
        edit: (contact, options = {}) => {
            return this.resourceContextPanel.open({
                content: 'contact/form',
                title: `Edit: ${contact.name}`,
                panelContentClass: 'px-4',
                contact,
                ...options,
            });
        },
        view: (contact, options = {}) => {
            return this.resourceContextPanel.open({
                contact,
                tabs: [
                    {
                        label: 'Overview',
                        component: 'contact/details',
                        contentClass: 'p-4',
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
                title: 'Create a new contact',
                acceptButtonText: 'Create Contact',
                component: 'contact/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', contact, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        edit: (contact, options = {}, saveOptions = {}) => {
            return this.modalsManager.show('modals/resource', {
                resource: contact,
                title: `Edit: ${contact.name}`,
                acceptButtonText: 'Save Changes',
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
