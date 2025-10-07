import ContactActionsService from './contact-actions';

export default class CustomerActionsService extends ContactActionsService {
    constructor() {
        super(...arguments);
        this.initialize('customer', { defaultAttributes: { type: 'customer', status: 'active' } });
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
                title: 'Create a new customer',

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
                title: `Edit: ${customer.name}`,

                customer,
                ...options,
            });
        },
        view: (customer, options = {}) => {
            return this.resourceContextPanel.open({
                customer,
                tabs: [
                    {
                        label: 'Overview',
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
                title: 'Create a new customer',
                acceptButtonText: 'Create Customer',
                component: 'customer/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', customer, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        edit: (customer, options = {}, saveOptions = {}) => {
            return this.modalsManager.show('modals/resource', {
                resource: customer,
                title: `Edit: ${customer.name}`,
                acceptButtonText: 'Save Changes',
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
