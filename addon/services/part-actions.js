import ResourceActionService, { inject as service } from '@fleetbase/ember-core/services/resource-action';
import { PANEL_DEFAULTS, closePanelsThen, registeredPanelTabs } from '../utils/context-panel';

export default class PartActionsService extends ResourceActionService {
    @service('universe/menu-service') menuService;

    get defaultCurrency() {
        return this.currentUser?.company?.currency || this.currentUser.currency || 'USD';
    }

    constructor() {
        super(...arguments);
        this.initialize('part', {
            defaultAttributes: {
                currency: this.defaultCurrency,
            },
        });
    }

    transition = {
        view: (part) => this.transitionTo('maintenance.parts.index.details', part),
        edit: (part) => this.transitionTo('maintenance.parts.index.edit', part),
        create: () => this.transitionTo('maintenance.parts.index.new'),
    };

    panel = {
        create: (attributes = {}) => {
            const part = this.createNewInstance(attributes);
            return this.resourceContextPanel.open({
                content: 'part/form',
                title: this.intl.t('common.create-a-new-resource', { resource: this.intl.t('resource.part')?.toLowerCase() }),
                saveOptions: {
                    callback: this.refresh,
                },
                useDefaultSaveTask: true,
                part,
            });
        },
        edit: (part) => {
            return this.resourceContextPanel.open({
                content: 'part/form',
                title: this.intl.t('common.edit-resource-name', { resourceName: part.name }),
                useDefaultSaveTask: true,
                part,
            });
        },
        view: (part, options = {}) => {
            return this.resourceContextPanel.open({
                part,
                title: part?.name,
                header: 'part/panel-header',
                actionButtons: [
                    { icon: 'edit', permission: 'fleet-ops update part', fn: () => closePanelsThen(this.resourceContextPanel, () => this.panel.edit(part)) },
                    { icon: 'trash', type: 'danger', permission: 'fleet-ops delete part', fn: () => this.delete(part, { onConfirm: () => this.resourceContextPanel.closeAll() }) },
                ],
                tabs: [{ key: 'overview', label: this.intl.t('common.overview'), component: 'part/details' }, ...registeredPanelTabs(this.menuService, 'fleet-ops:component:part:details')],
                ...PANEL_DEFAULTS,
                ...options,
            });
        },
    };

    modal = {
        create: (attributes = {}, options = {}, saveOptions = {}) => {
            const part = this.createNewInstance(attributes);
            return this.modalsManager.show('modals/resource', {
                resource: part,
                title: this.intl.t('common.create-a-new-resource', { resource: this.intl.t('resource.part')?.toLowerCase() }),
                acceptButtonText: this.intl.t('common.create-resource', { resource: this.intl.t('resource.part') }),
                component: 'part/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', part, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        edit: (part, options = {}, saveOptions = {}) => {
            return this.modalsManager.show('modals/resource', {
                resource: part,
                title: this.intl.t('common.edit-resource-name', { resourceName: part.name }),
                acceptButtonText: this.intl.t('common.save-changes'),
                saveButtonIcon: 'save',
                component: 'part/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', part, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        view: (part, options = {}) => {
            return this.modalsManager.show('modals/resource', {
                resource: part,
                title: part.name,
                component: 'part/details',
                ...options,
            });
        },
    };
}
