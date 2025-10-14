import ResourceActionService from '@fleetbase/ember-core/services/resource-action';

export default class TelematicActionsService extends ResourceActionService {
    constructor() {
        super(...arguments);
        this.initialize('telematic');
    }

    transition = {
        view: (telematic) => this.transitionTo('connectivity.telematics.index.details', telematic),
        edit: (telematic) => this.transitionTo('connectivity.telematics.index.edit', telematic),
        create: () => this.transitionTo('connectivity.telematics.index.new'),
    };

    panel = {
        create: (attributes = {}) => {
            const telematic = this.createNewInstance(attributes);
            return this.resourceContextPanel.open({
                content: 'telematic/form',
                title: this.intl.t('common.create-a-new-resource', { resource: this.intl.t('resource.telematic')?.toLowerCase() }),
                useDefaultSaveTask: true,
                saveOptions: {
                    callback: this.refresh,
                },
                telematic,
            });
        },
        edit: (telematic) => {
            return this.resourceContextPanel.open({
                content: 'telematic/form',
                title: this.intl.t('common.edit-resource-name', { resourceName: telematic.name }),
                useDefaultSaveTask: true,
                telematic,
            });
        },
        view: (telematic) => {
            return this.resourceContextPanel.open({
                telematic,
                tabs: [
                    {
                        label: this.intl.t('common.overview'),
                        component: 'telematic/details',
                    },
                ],
            });
        },
    };

    modal = {
        create: (attributes = {}, options = {}, saveOptions = {}) => {
            const telematic = this.createNewInstance(attributes);
            return this.modalsManager.show('modals/resource', {
                resource: telematic,
                title: this.intl.t('common.create-a-new-resource', { resource: this.intl.t('resource.telematic')?.toLowerCase() }),
                acceptButtonText: this.intl.t('common.create-resource', { resource: this.intl.t('resource.telematic') }),
                component: 'telematic/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', telematic, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        edit: (telematic, options = {}, saveOptions = {}) => {
            return this.modalsManager.show('modals/resource', {
                resource: telematic,
                title: this.intl.t('common.edit-resource-name', { resourceName: telematic.name }),
                acceptButtonText: this.intl.t('common.save-changes'),
                saveButtonIcon: 'save',
                component: 'telematic/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', telematic, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        view: (telematic, options = {}) => {
            return this.modalsManager.show('modals/resource', {
                resource: telematic,
                title: telematic.name,
                component: 'telematic/details',
                ...options,
            });
        },
    };
}
