import ResourceActionService from '@fleetbase/ember-core/services/resource-action';

export default class SensorActionsService extends ResourceActionService {
    constructor() {
        super(...arguments);
        this.initialize('sensor');
    }

    transition = {
        view: (sensor) => this.transitionTo('connectivity.sensors.index.details', sensor),
        edit: (sensor) => this.transitionTo('connectivity.sensors.index.edit', sensor),
        create: () => this.transitionTo('connectivity.sensors.index.new'),
    };

    panel = {
        create: (attributes = {}) => {
            const sensor = this.createNewInstance(attributes);
            return this.resourceContextPanel.open({
                content: 'sensor/form',
                title: this.intl.t('common.create-a-new-resource', { resource: this.intl.t('resource.sensor')?.toLowerCase() }),
                useDefaultSaveTask: true,
                saveOptions: {
                    callback: this.refresh,
                },
                sensor,
            });
        },
        edit: (sensor) => {
            return this.resourceContextPanel.open({
                content: 'sensor/form',
                title: this.intl.t('common.edit-resource-name', { resourceName: sensor.name }),
                useDefaultSaveTask: true,
                sensor,
            });
        },
        view: (sensor) => {
            return this.resourceContextPanel.open({
                sensor,
                tabs: [
                    {
                        label: this.intl.t('common.overview'),
                        component: 'sensor/details',
                    },
                ],
            });
        },
    };

    modal = {
        create: (attributes = {}, options = {}, saveOptions = {}) => {
            const sensor = this.createNewInstance(attributes);
            return this.modalsManager.show('modals/resource', {
                resource: sensor,
                title: this.intl.t('common.create-a-new-resource', { resource: this.intl.t('resource.sensor')?.toLowerCase() }),
                acceptButtonText: this.intl.t('common.create-resource', { resource: this.intl.t('resource.sensor') }),
                component: 'sensor/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', sensor, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        edit: (sensor, options = {}, saveOptions = {}) => {
            return this.modalsManager.show('modals/resource', {
                resource: sensor,
                title: this.intl.t('common.edit-resource-name', { resourceName: sensor.name }),
                acceptButtonText: this.intl.t('common.save-changes'),
                saveButtonIcon: 'save',
                component: 'sensor/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', sensor, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        view: (sensor, options = {}) => {
            return this.modalsManager.show('modals/resource', {
                resource: sensor,
                title: sensor.name,
                component: 'sensor/details',
                ...options,
            });
        },
    };
}
