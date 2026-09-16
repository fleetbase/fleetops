import ResourceActionService, { inject as service } from '@fleetbase/ember-core/services/resource-action';
import { PANEL_DEFAULTS, closePanelsThen, registeredPanelTabs } from '../utils/context-panel';

export default class EquipmentActionsService extends ResourceActionService {
    @service('universe/menu-service') menuService;

    get defaultCurrency() {
        return this.currentUser?.company?.currency || this.currentUser.currency || 'USD';
    }

    constructor() {
        super(...arguments);
        this.initialize('equipment', {
            defaultAttributes: {
                status: 'available',
                currency: this.defaultCurrency,
            },
        });
    }

    transition = {
        view: (equipment) => this.transitionTo('maintenance.equipment.index.details', equipment),
        edit: (equipment) => this.transitionTo('maintenance.equipment.index.edit', equipment),
        create: () => this.transitionTo('maintenance.equipment.index.new'),
    };

    panel = {
        create: (attributes = {}) => {
            const equipment = this.createNewInstance(attributes);
            return this.resourceContextPanel.open({
                content: 'equipment/form',
                title: this.intl.t('common.create-a-new-resource', { resource: this.intl.t('resource.equipment')?.toLowerCase() }),
                useDefaultSaveTask: true,
                saveOptions: {
                    callback: this.refresh,
                },
                equipment,
            });
        },
        edit: (equipment) => {
            return this.resourceContextPanel.open({
                content: 'equipment/form',
                title: this.intl.t('common.edit-resource-name', { resourceName: equipment.name }),
                useDefaultSaveTask: true,
                equipment,
            });
        },
        view: (equipment, options = {}) => {
            return this.resourceContextPanel.open({
                equipment,
                title: equipment?.name,
                header: 'equipment/panel-header',
                actionButtons: [
                    { icon: 'edit', permission: 'fleet-ops update equipment', fn: () => closePanelsThen(this.resourceContextPanel, () => this.panel.edit(equipment)) },
                    { icon: 'trash', type: 'danger', permission: 'fleet-ops delete equipment', fn: () => this.delete(equipment, { onConfirm: () => this.resourceContextPanel.closeAll() }) },
                ],
                tabs: [
                    { key: 'overview', label: this.intl.t('common.overview'), component: 'equipment/details' },
                    ...registeredPanelTabs(this.menuService, 'fleet-ops:component:equipment:details'),
                ],
                ...PANEL_DEFAULTS,
                ...options,
            });
        },
    };

    modal = {
        create: (attributes = {}, options = {}, saveOptions = {}) => {
            const equipment = this.createNewInstance(attributes);
            return this.modalsManager.show('modals/resource', {
                resource: equipment,
                title: this.intl.t('common.create-a-new-resource', { resource: this.intl.t('resource.equipment')?.toLowerCase() }),
                acceptButtonText: this.intl.t('common.create-resource', { resource: this.intl.t('resource.equipment') }),
                component: 'equipment/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', equipment, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        edit: (equipment, options = {}, saveOptions = {}) => {
            return this.modalsManager.show('modals/resource', {
                resource: equipment,
                title: this.intl.t('common.edit-resource-name', { resourceName: equipment.name }),
                acceptButtonText: this.intl.t('common.save-changes'),
                saveButtonIcon: 'save',
                component: 'equipment/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', equipment, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        view: (equipment, options = {}) => {
            return this.modalsManager.show('modals/resource', {
                resource: equipment,
                title: equipment.name,
                component: 'equipment/details',
                ...options,
            });
        },
    };
}
