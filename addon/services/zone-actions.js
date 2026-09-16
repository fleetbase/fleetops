import ResourceActionService from '@fleetbase/ember-core/services/resource-action';
import { PANEL_DEFAULTS, closePanelsThen } from '../utils/context-panel';

export default class ZoneActionsService extends ResourceActionService {
    constructor() {
        super(...arguments);
        this.initialize('zone');
    }

    transition = {
        view: (zone) => this.transitionTo('operations.orders.index', { queryParams: { zone: zone.id } }),
        edit: (zone) => this.transitionTo('operations.orders.index', { queryParams: { zone: zone.id, editing: zone.id } }),
        create: () => this.transitionTo('operations.orders.index', { queryParams: { creating: 'zone' } }),
    };

    panel = {
        create: (attributes = {}) => {
            const zone = this.createNewInstance(attributes);
            return this.resourceContextPanel.open({
                content: 'zone/form',
                title: this.intl.t('common.create-a-new-resource', { resource: this.intl.t('resource.zone')?.toLowerCase() }),
                saveOptions: {
                    callback: this.refresh,
                },
                useDefaultSaveTask: true,
                zone,
            });
        },
        edit: (zone) => {
            return this.resourceContextPanel.open({
                content: 'zone/form',
                title: this.intl.t('common.edit-resource-name', { resourceName: zone.name }),
                useDefaultSaveTask: true,
                zone,
            });
        },
        view: (zone, options = {}) => {
            return this.resourceContextPanel.open({
                zone,
                title: zone?.name,
                actionButtons: [
                    { icon: 'pencil', permission: 'fleet-ops update zone', fn: () => closePanelsThen(this.resourceContextPanel, () => this.panel.edit(zone)) },
                    {
                        icon: 'ellipsis-h',
                        iconPrefix: 'fas',
                        renderInPlace: true,
                        items: [
                            {
                                text: this.intl.t('zone.actions.edit-boundary'),
                                icon: 'draw-polygon',
                                permission: 'fleet-ops update zone',
                                fn: () => closePanelsThen(this.resourceContextPanel, () => this.transition.edit(zone)),
                            },
                            { separator: true },
                            {
                                text: this.intl.t('common.delete'),
                                icon: 'trash',
                                class: 'text-red-500',
                                permission: 'fleet-ops delete zone',
                                fn: () => this.delete(zone, { onConfirm: () => this.resourceContextPanel.closeAll() }),
                            },
                        ],
                    },
                ],
                tabs: [{ key: 'overview', label: this.intl.t('common.overview'), component: 'zone/details' }],
                ...PANEL_DEFAULTS,
                ...options,
            });
        },
    };

    modal = {
        create: (attributes = {}, options = {}, saveOptions = {}) => {
            const zone = this.createNewInstance(attributes);
            return this.modalsManager.show('modals/resource', {
                resource: zone,
                title: this.intl.t('common.create-a-new-resource', { resource: this.intl.t('resource.zone')?.toLowerCase() }),
                acceptButtonText: this.intl.t('common.create-resource', { resource: this.intl.t('resource.zone') }),
                component: 'zone/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', zone, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        edit: (zone, options = {}, saveOptions = {}) => {
            return this.modalsManager.show('modals/resource', {
                resource: zone,
                title: this.intl.t('common.edit-resource-name', { resourceName: zone.name }),
                acceptButtonText: this.intl.t('common.save-changes'),
                saveButtonIcon: 'save',
                component: 'zone/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', zone, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        view: (zone, options = {}) => {
            return this.modalsManager.show('modals/resource', {
                resource: zone,
                title: zone.name,
                component: 'zone/details',
                ...options,
            });
        },
    };
}
