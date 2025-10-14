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
                title: this.intl.t('common.create-a-new-resource', { resource: this.intl.t('resource.Integrated Vendor')?.toLowerCase() }),
                useDefaultSaveTask: true,
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
                title: this.intl.t('common.edit-resource-name', { resourceName: integratedVendor.name }),
                useDefaultSaveTask: true,
                integratedVendor,
                ...options,
            });
        },
        view: (integratedVendor, options = {}) => {
            return this.resourceContextPanel.open({
                integratedVendor,
                tabs: [
                    {
                        label: this.intl.t('common.overview'),
                        component: 'integrated-vendor/details',
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
                title: this.intl.t('common.create-a-new-resource', { resource: this.intl.t('resource.Integrated Vendor')?.toLowerCase() }),
                acceptButtonText: this.intl.t('common.create-resource', { resource: this.intl.t('resource.customer') }),
                component: 'integrated-vendor/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', integratedVendor, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        edit: (integratedVendor, options = {}, saveOptions = {}) => {
            return this.modalsManager.show('modals/resource', {
                resource: integratedVendor,
                title: this.intl.t('common.edit-resource-name', { resourceName: integratedVendor.name }),
                acceptButtonText: this.intl.t('common.save-changes'),
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
