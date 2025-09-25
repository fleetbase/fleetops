import ResourceActionService from '@fleetbase/ember-core/services/resource-action';

export default class IntegratedVendorActionsService extends ResourceActionService {
    constructor() {
        super(...arguments);
        this.initialize('integrated-vendor');
    }

    transition = {
        view: (integratedVendor) => this.transitionTo('management.vendors.integrated.details', integratedVendor),
        edit: (integratedVendor) => this.transitionTo('management.vendors.integrated.edit', integratedVendor),
        create: () => this.transitionTo('management.vendors.integrated.new'),
    };

    panel = {
        create: (attributes = {}, options = {}) => {
            const integratedVendor = this.createNewInstance(attributes);
            return this.resourceContextPanel.open({
                content: 'integrated-vendor/form',
                title: 'Create a new Integrated Vendor',
                panelContentClass: 'px-4',
                saveOptions: {
                    callback: this.refresh,
                },
                integratedVendor,
                ...options,
            });
        },
        edit: (integratedVendor, options = {}) => {
            return this.resourceContextPanel.open({
                content: 'integrated-vendor/form',
                title: `Edit: ${integratedVendor.name}`,
                panelContentClass: 'px-4',
                integratedVendor,
                ...options,
            });
        },
        view: (integratedVendor, options = {}) => {
            return this.resourceContextPanel.open({
                integratedVendor,
                tabs: [
                    {
                        label: 'Overview',
                        component: 'integrated-vendor/details',
                        contentClass: 'p-4',
                    },
                ],
                ...options,
            });
        },
    };

    modal = {
        create: (attributes = {}, options = {}, saveOptions = {}) => {
            const integratedVendor = this.createNewInstance(attributes);
            return this.modalsManager.show('modals/resource', {
                resource: integratedVendor,
                title: 'Create a new Integrated Vendor',
                acceptButtonText: 'Create Customer',
                component: 'integrated-vendor/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', integratedVendor, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        edit: (integratedVendor, options = {}, saveOptions = {}) => {
            return this.modalsManager.show('modals/resource', {
                resource: integratedVendor,
                title: `Edit: ${integratedVendor.name}`,
                acceptButtonText: 'Save Changes',
                saveButtonIcon: 'save',
                component: 'integrated-vendor/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', integratedVendor, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        view: (integratedVendor, options = {}) => {
            return this.modalsManager.show('modals/resource', {
                resource: integratedVendor,
                title: integratedVendor.name,
                component: 'integrated-vendor/details',
                ...options,
            });
        },
    };
}
