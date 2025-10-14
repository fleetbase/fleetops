import ResourceActionService from '@fleetbase/ember-core/services/resource-action';

export default class FleetActionsService extends ResourceActionService {
    constructor() {
        super(...arguments);
        this.initialize('fleet', { defaultAttributes: { status: 'active' } });
    }

    transition = {
        view: (fleet) => this.transitionTo('management.fleets.index.details', fleet),
        edit: (fleet) => this.transitionTo('management.fleets.index.edit', fleet),
        create: () => this.transitionTo('management.fleets.index.new'),
    };

    panel = {
        create: (attributes = {}, options = {}) => {
            const fleet = this.createNewInstance(attributes);
            return this.resourceContextPanel.open({
                content: 'fleet/form',
                title: this.intl.t('common.create-a-new-resource', { resource: this.intl.t('resource.fleet')?.toLowerCase() }),
                saveOptions: {
                    callback: this.refresh,
                },
                useDefaultSaveTask: true,
                fleet,
                ...options,
            });
        },
        edit: (fleet, options = {}) => {
            return this.resourceContextPanel.open({
                content: 'fleet/form',
                title: this.intl.t('common.edit-resource-name', { resourceName: fleet.name }),
                useDefaultSaveTask: true,
                fleet,
                ...options,
            });
        },
        view: (fleet, options = {}) => {
            return this.resourceContextPanel.open({
                fleet,
                tabs: [
                    {
                        label: this.intl.t('common.overview'),
                        component: 'fleet/details',
                    },
                ],
                ...options,
            });
        },
    };

    modal = {
        create: (attributes = {}, options = {}, saveOptions = {}) => {
            const fleet = this.createNewInstance(attributes);
            return this.modalsManager.show('modals/resource', {
                resource: fleet,
                title: this.intl.t('common.create-a-new-resource', { resource: this.intl.t('resource.fleet')?.toLowerCase() }),
                acceptButtonText: this.intl.t('common.create-resource', { resource: this.intl.t('resource.fleet') }),
                component: 'fleet/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', fleet, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        edit: (fleet, options = {}, saveOptions = {}) => {
            return this.modalsManager.show('modals/resource', {
                resource: fleet,
                title: this.intl.t('common.edit-resource-name', { resourceName: fleet.name }),
                acceptButtonText: this.intl.t('common.save-changes'),
                saveButtonIcon: 'save',
                component: 'fleet/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', fleet, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        view: (fleet, options = {}) => {
            return this.modalsManager.show('modals/resource', {
                resource: fleet,
                title: fleet.name,
                component: 'fleet/details',
                ...options,
            });
        },
    };
}
