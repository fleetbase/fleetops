import ContactActionsService from './contact-actions';

export default class CustomerActionsService extends ContactActionsService {
    constructor() {
        super(...arguments);
        this.initialize('contact', { defaultAttributes: { type: 'customer', status: 'active' } });
    }

    transition = {
        view: (customer) => this.transitionTo('management.contacts.customers.details', customer),
        edit: (customer) => this.transitionTo('management.contacts.customers.edit', customer),
        create: () => this.transitionTo('management.contacts.customers.new'),
    };

    panel = {
        create: (attributes = {}, options = {}) => {
            const customer = this.createNewInstance(attributes);
            return this.resourceContextPanel.open({
                content: 'customer/form',
                title: this.intl.t('common.create-a-new-resource', { resource: this.intl.t('resource.customer')?.toLowerCase() }),
                useDefaultSaveTask: true,
                saveOptions: {
                    callback: this.refresh,
                },
                customer,
                ...options,
            });
        },
        edit: (customer, options = {}) => {
            return this.resourceContextPanel.open({
                content: 'customer/form',
                title: this.intl.t('common.edit-resource-name', { resourceName: customer.name }),
                useDefaultSaveTask: true,
                customer,
                ...options,
            });
        },
        view: (customer, options = {}) => {
            return this.resourceContextPanel.open({
                customer,
                tabs: [
                    {
                        label: this.intl.t('common.overview'),
                        component: 'customer/details',
                    },
                ],
                ...options,
            });
        },
    };

    modal = {
        create: (attributes = {}, options = {}, saveOptions = {}) => {
            const customer = this.createNewInstance(attributes);
            return this.modalsManager.show('modals/resource', {
                resource: customer,
                title: this.intl.t('common.create-a-new-resource', { resource: this.intl.t('resource.customer')?.toLowerCase() }),
                acceptButtonText: this.intl.t('common.create-resource', { resource: this.intl.t('resource.customer') }),
                component: 'customer/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', customer, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        edit: (customer, options = {}, saveOptions = {}) => {
            return this.modalsManager.show('modals/resource', {
                resource: customer,
                title: this.intl.t('common.edit-resource-name', { resourceName: customer.name }),
                acceptButtonText: this.intl.t('common.save-changes'),
                saveButtonIcon: 'save',
                component: 'customer/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', customer, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        view: (customer, options = {}) => {
            return this.modalsManager.show('modals/resource', {
                resource: customer,
                title: customer.name,
                component: 'customer/details',
                ...options,
            });
        },
    };
}
