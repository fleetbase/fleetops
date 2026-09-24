import ResourceActionService from '@fleetbase/ember-core/services/resource-action';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { PANEL_DEFAULTS, closePanelsThen, registeredPanelTabs } from '../utils/context-panel';
import { confirmLoginAction, hasInactiveLogin, hasLinkedUser, hasManagedLogin, linkedUserStatus, updateProfileFromResponse } from '../utils/profile-login-actions';

export default class ContactActionsService extends ResourceActionService {
    @service fetch;
    @service placeActions;
    @service notifications;
    @service hostRouter;
    @service('universe/menu-service') menuService;
    @service('universe/extension-manager') extensionManager;

    constructor() {
        super(...arguments);
        this.initialize('contact', { defaultAttributes: { type: 'contact', status: 'active' } });
    }

    transition = {
        view: (contact) => this.transitionTo('management.contacts.index.details', contact),
        edit: (contact) => this.transitionTo('management.contacts.index.edit', contact),
        create: () => this.transitionTo('management.contacts.index.new'),
    };

    panel = {
        create: (attributes = {}, options = {}) => {
            const contact = this.createNewInstance(attributes);
            return this.resourceContextPanel.open({
                content: 'contact/form',
                title: this.intl.t('common.create-a-new-resource', { resource: this.intl.t('resource.contact')?.toLowerCase() }),
                saveOptions: {
                    callback: this.refresh,
                },
                useDefaultSaveTask: true,
                contact,
                ...options,
            });
        },
        edit: (contact, options = {}) => {
            return this.resourceContextPanel.open({
                content: 'contact/form',
                title: this.intl.t('common.edit-resource-name', { resourceName: contact.name }),
                contact,
                useDefaultSaveTask: true,
                ...options,
            });
        },
        view: (contact, options = {}) => {
            return this.resourceContextPanel.open({
                contact,
                title: contact?.name,
                header: 'contact/panel-header',
                actionButtons: this.panelActionButtons(contact),
                tabs: [
                    { key: 'overview', label: this.intl.t('common.overview'), component: 'contact/details' },
                    ...registeredPanelTabs(this.menuService, 'fleet-ops:component:contact:details'),
                ],
                ...PANEL_DEFAULTS,
                ...options,
            });
        },
    };

    modal = {
        create: (attributes = {}, options = {}, saveOptions = {}) => {
            const contact = this.createNewInstance(attributes);
            return this.modalsManager.show('modals/resource', {
                resource: contact,
                title: this.intl.t('common.create-a-new-resource', { resource: this.intl.t('resource.contact')?.toLowerCase() }),
                acceptButtonText: this.intl.t('common.create-resource', { resource: this.intl.t('resource.contact') }),
                component: 'contact/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', contact, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        edit: (contact, options = {}, saveOptions = {}) => {
            return this.modalsManager.show('modals/resource', {
                resource: contact,
                title: this.intl.t('common.edit-resource-name', { resourceName: contact.name }),
                acceptButtonText: this.intl.t('common.save-changes'),
                saveButtonIcon: 'save',
                component: 'contact/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', contact, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        view: (contact, options = {}) => {
            return this.modalsManager.show('modals/resource', {
                resource: contact,
                title: contact.name,
                component: 'contact/details',
                ...options,
            });
        },
    };

    @action async viewPlace(contact) {
        const place = await contact.place;
        if (place) {
            this.placeActions.modal.view(place);
        }
    }

    @action async editPlace(contact) {
        const place = await contact.place;
        if (place) {
            this.placeActions.modal.edit(place);
        }
    }

    @action async createPlace(contact) {
        return this.placeActions.modal.create(
            {},
            {},
            {
                callback: async (place) => {
                    contact.set('place_uuid', place.id);
                    await contact.save();
                },
            }
        );
    }

    hasLinkedUser(contact) {
        return hasLinkedUser(contact);
    }

    /**
     * True when the contact has a login account managed from Fleet-Ops. A
     * contact owned by a staff member is managed from IAM instead.
     */
    hasManagedLogin(contact) {
        return hasManagedLogin(contact);
    }

    linkedUserStatus(contact) {
        return linkedUserStatus(contact);
    }

    hasInactiveLogin(contact) {
        return hasInactiveLogin(contact);
    }

    isCustomerPortalInstalled() {
        return this.extensionManager.isInstalled('@fleetbase/customer-portal-engine');
    }

    /**
     * The header buttons of a contact or customer panel, as on the details
     * route: edit, then the portal account menu when a login is linked.
     */
    panelActionButtons(contact) {
        const buttons = [{ icon: 'pencil', fn: () => closePanelsThen(this.resourceContextPanel, () => this.panel.edit(contact)) }];
        const accountActionButton = this.accountActionButton(contact);
        if (accountActionButton) {
            buttons.push(accountActionButton);
        }

        return buttons;
    }

    accountActionButton(contact, options = {}) {
        if (!this.hasManagedLogin(contact)) {
            return null;
        }

        return {
            icon: 'ellipsis-h',
            iconPrefix: 'fas',
            renderInPlace: true,
            items: this.accountActionItems(contact, options),
        };
    }

    accountActionItems(contact, options = {}) {
        const actions = [
            {
                text: this.intl.t('profile-account.actions.reset-password'),
                icon: 'key',
                fn: () => this.openResetPasswordModal(contact),
            },
            {
                text: this.intl.t('profile-account.actions.send-credentials'),
                icon: 'paper-plane',
                fn: () => this.confirmSendCredentials(contact),
            },
            this.hasInactiveLogin(contact)
                ? {
                      text: this.intl.t('profile-account.actions.reactivate-login'),
                      icon: 'unlock',
                      fn: () => this.confirmReactivatePortalLogin(contact),
                  }
                : {
                      text: this.intl.t('profile-account.actions.deactivate-login'),
                      icon: 'lock',
                      fn: () => this.confirmDeactivatePortalLogin(contact),
                      class: 'text-red-500 hover:text-red-600',
                  },
        ];

        if (this.isCustomerPortalInstalled()) {
            actions.push(
                {
                    separator: true,
                },
                {
                    text: this.intl.t('profile-account.actions.convert-to-vendor'),
                    icon: 'building',
                    fn: () => this.openConvertToVendorModal(contact, options),
                }
            );
        }

        return actions;
    }

    accountRowActionItems(options = {}) {
        const hasCustomerPortal = this.isCustomerPortalInstalled();
        const hasManagedLogin = (contact) => this.hasManagedLogin(contact);

        const actions = [
            {
                label: this.intl.t('profile-account.actions.reset-password'),
                icon: 'key',
                fn: (contact) => this.openResetPasswordModal(contact),
                isVisible: hasManagedLogin,
            },
            {
                label: this.intl.t('profile-account.actions.send-credentials'),
                icon: 'paper-plane',
                fn: (contact) => this.confirmSendCredentials(contact),
                isVisible: hasManagedLogin,
            },
            {
                label: this.intl.t('profile-account.actions.deactivate-login'),
                icon: 'lock',
                class: 'text-red-500 hover:text-red-600',
                fn: (contact) => this.confirmDeactivatePortalLogin(contact),
                isVisible: (contact) => hasManagedLogin(contact) && !this.hasInactiveLogin(contact),
            },
            {
                label: this.intl.t('profile-account.actions.reactivate-login'),
                icon: 'unlock',
                fn: (contact) => this.confirmReactivatePortalLogin(contact),
                isVisible: (contact) => hasManagedLogin(contact) && this.hasInactiveLogin(contact),
            },
        ];

        if (hasCustomerPortal) {
            actions.push({
                label: this.intl.t('profile-account.actions.convert-to-vendor'),
                icon: 'building',
                fn: (contact) => this.openConvertToVendorModal(contact, options),
                isVisible: hasManagedLogin,
            });
        }

        return actions;
    }

    openResetPasswordModal(contact) {
        this.modalsManager.show('modals/reset-profile-credentials', {
            profile: contact,
            endpoint: 'customers/reset-credentials',
            payload: (profile) => ({ customer: profile?.id }),
        });
    }

    openConvertToVendorModal(contact, options = {}) {
        this.modalsManager.show('modals/convert-customer-to-vendor', {
            customer: contact,
            onConverted: (vendor) => {
                if (typeof options.onConverted === 'function') {
                    options.onConverted(vendor);
                    return;
                }

                if (vendor?.id) {
                    this.hostRouter.transitionTo('console.fleet-ops.management.vendors.index.details', vendor.id);
                }
            },
        });
    }

    confirmSendCredentials(contact) {
        this.confirmLoginAction(contact, {
            title: this.intl.t('profile-account.prompts.send-portal-credentials-title'),
            body: this.intl.t('profile-account.prompts.send-portal-credentials-body'),
            acceptButtonText: this.intl.t('profile-account.actions.send-credentials'),
            endpoint: 'customers/send-credentials',
            successMessage: this.intl.t('profile-account.prompts.send-portal-credentials-success'),
        });
    }

    confirmDeactivatePortalLogin(contact) {
        this.confirmLoginAction(contact, {
            title: this.intl.t('profile-account.actions.deactivate-login'),
            body: this.intl.t('profile-account.prompts.deactivate-portal-login-body'),
            acceptButtonText: this.intl.t('profile-account.actions.deactivate-login'),
            acceptButtonScheme: 'danger',
            endpoint: 'customers/deactivate-portal-login',
            successMessage: this.intl.t('profile-account.prompts.deactivate-portal-login-success'),
        });
    }

    confirmReactivatePortalLogin(contact) {
        this.confirmLoginAction(contact, {
            title: this.intl.t('profile-account.actions.reactivate-login'),
            body: this.intl.t('profile-account.prompts.reactivate-portal-login-body'),
            acceptButtonText: this.intl.t('profile-account.actions.reactivate-login'),
            endpoint: 'customers/reactivate-portal-login',
            successMessage: this.intl.t('profile-account.prompts.reactivate-portal-login-success'),
        });
    }

    confirmLoginAction(contact, options = {}) {
        return confirmLoginAction(this, contact, { payload: { customer: contact?.id }, ...options });
    }

    updateContactFromResponse(contact, response) {
        return updateProfileFromResponse(contact, response);
    }
}
