import ResourceActionService, { inject as service } from '@fleetbase/ember-core/services/resource-action';

export default class MaintenanceActionsService extends ResourceActionService {
    @service currentUser;

    constructor() {
        super(...arguments);
        this.initialize('maintenance', {
            defaultAttributes: {
                currency: this.defaultCurrency,
            },
        });
    }

    get defaultCurrency() {
        return this.currentUser?.company?.currency || 'USD';
    }

    transition = {
        view: (maintenance) => this.transitionTo('maintenance.maintenances.index.details', maintenance),
        edit: (maintenance) => this.transitionTo('maintenance.maintenances.index.edit', maintenance),
        create: () => this.transitionTo('maintenance.maintenances.index.new'),
    };

    panel = {
        create: (attributes = {}) => {
            const maintenance = this.createNewInstance(attributes);
            return this.resourceContextPanel.open({
                content: 'maintenance/form',
                title: this.intl.t('common.create-a-new-resource', { resource: this.intl.t('resource.maintenance')?.toLowerCase() }),
                useDefaultSaveTask: true,
                saveOptions: { callback: this.refresh },
                maintenance,
            });
        },
        edit: (maintenance) => {
            return this.resourceContextPanel.open({
                content: 'maintenance/form',
                title: this.intl.t('common.edit-resource-name', { resourceName: maintenance.summary }),
                useDefaultSaveTask: true,
                maintenance,
            });
        },
        view: (maintenance) => {
            return this.resourceContextPanel.open({
                maintenance,
                tabs: [{ label: this.intl.t('common.overview'), component: 'maintenance/details' }],
            });
        },
    };

    modal = {
        create: (attributes = {}, options = {}, saveOptions = {}) => {
            const maintenance = this.createNewInstance(attributes);
            return this.modalsManager.show('modals/resource', {
                resource: maintenance,
                title: this.intl.t('common.create-a-new-resource', { resource: this.intl.t('resource.maintenance')?.toLowerCase() }),
                acceptButtonText: this.intl.t('common.create-resource', { resource: this.intl.t('resource.maintenance') }),
                component: 'maintenance/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', maintenance, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        edit: (maintenance, options = {}, saveOptions = {}) => {
            return this.modalsManager.show('modals/resource', {
                resource: maintenance,
                title: this.intl.t('common.edit-resource-name', { resourceName: maintenance.summary }),
                acceptButtonText: this.intl.t('common.save-changes'),
                saveButtonIcon: 'save',
                component: 'maintenance/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', maintenance, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        view: (maintenance, options = {}) => {
            return this.modalsManager.show('modals/resource', {
                resource: maintenance,
                title: maintenance.summary,
                component: 'maintenance/details',
                ...options,
            });
        },
    };
}
