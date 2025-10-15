import ResourceActionService from '@fleetbase/ember-core/services/resource-action';

export default class DeviceEventActionsService extends ResourceActionService {
    constructor() {
        super(...arguments);
        this.initialize('device');
    }

    transition = {
        view: (deviceEvent) => this.transitionTo('connectivity.events.index.details', deviceEvent),
    };

    panel = {
        view: (deviceEvent) => {
            return this.resourceContextPanel.open({
                deviceEvent,
                tabs: [
                    {
                        label: this.intl.t('common.overview'),
                        component: 'device-event/details',
                    },
                ],
            });
        },
    };

    modal = {
        view: (deviceEvent, options = {}) => {
            return this.modalsManager.show('modals/resource', {
                resource: deviceEvent,
                title: deviceEvent.name,
                component: 'device-event/details',
                ...options,
            });
        },
    };
}
