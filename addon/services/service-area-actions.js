import ResourceActionService from '@fleetbase/ember-core/services/resource-action';
import { tracked } from '@glimmer/tracking';
import { debug } from '@ember/debug';
import { task } from 'ember-concurrency';

export default class ServiceAreaActionsService extends ResourceActionService {
    @tracked serviceAreas = [];

    constructor() {
        super(...arguments);
        this.initialize('service-area');
    }

    transition = {
        view: (serviceArea) => this.transitionTo('operations.orders.index', { queryParams: { service_area: serviceArea.id } }),
        edit: (serviceArea) => this.transitionTo('operations.orders.index', { queryParams: { service_area: serviceArea.id, editing: serviceArea.id } }),
        create: () => this.transitionTo('operations.orders.index', { queryParams: { creating: 'service_area' } }),
    };

    panel = {
        create: (attributes = {}) => {
            const serviceArea = this.createNewInstance(attributes);
            return this.resourceContextPanel.open({
                content: 'service-area/form',
                title: this.intl.t('common.create-a-new-resource', { resource: this.intl.t('resource.service area')?.toLowerCase() }),
                useDefaultSaveTask: true,
                saveOptions: {
                    callback: this.refresh,
                },
                serviceArea,
            });
        },
        edit: (serviceArea) => {
            return this.resourceContextPanel.open({
                content: 'service-area/form',
                title: this.intl.t('common.edit-resource-name', { resourceName: serviceArea.name }),
                useDefaultSaveTask: true,
                serviceArea,
            });
        },
        view: (serviceArea) => {
            return this.resourceContextPanel.open({
                serviceArea,
                tabs: [
                    {
                        label: this.intl.t('common.overview'),
                        component: 'service-area/details',
                    },
                ],
            });
        },
    };

    modal = {
        create: (attributes = {}, options = {}, saveOptions = {}) => {
            const serviceArea = this.createNewInstance(attributes);
            saveOptions = { ...(options.saveOptions ?? {}), ...(saveOptions ?? {}) };
            return this.modalsManager.show('modals/resource', {
                resource: serviceArea,
                title: this.intl.t('common.create-a-new-resource', { resource: this.intl.t('resource.service-area')?.toLowerCase() }),
                acceptButtonText: this.intl.t('common.create-resource', { resource: this.intl.t('resource.service-area') }),
                component: 'service-area/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', serviceArea, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        edit: (serviceArea, options = {}, saveOptions = {}) => {
            saveOptions = { ...(options.saveOptions ?? {}), ...(saveOptions ?? {}) };
            return this.modalsManager.show('modals/resource', {
                resource: serviceArea,
                title: this.intl.t('common.edit-resource-name', { resourceName: serviceArea.name }),
                acceptButtonText: this.intl.t('common.save-changes'),
                saveButtonIcon: 'save',
                component: 'service-area/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', serviceArea, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        view: (serviceArea, options = {}) => {
            return this.modalsManager.show('modals/resource', {
                resource: serviceArea,
                title: serviceArea.name,
                component: 'service-area/details',
                ...options,
            });
        },
    };

    @task *loadAll() {
        try {
            const serviceAreas = yield this.store.findAll('service-area');
            this.serviceAreas = serviceAreas;
            return serviceAreas;
        } catch (err) {
            debug('Unable to load service areas: ' + err.message);
        }
    }
}
