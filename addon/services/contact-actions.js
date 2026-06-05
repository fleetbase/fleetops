import ResourceActionService from '@fleetbase/ember-core/services/resource-action';
import { inject as service } from '@ember/service';
import { action, get } from '@ember/object';

const INTERNAL_NAMESPACE = 'int/v1';

export default class ContactActionsService extends ResourceActionService {
    @service fetch;
    @service placeActions;
    @service notifications;
    @service hostRouter;
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
                tabs: [
                    {
                        label: this.intl.t('common.overview'),
                        component: 'contact/details',
                    },
                ],
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
        const user = contact?.user;
        return Boolean(contact?.user_uuid || (user && (get(user, 'id') || get(user, 'uuid'))));
    }

    linkedUserStatus(contact) {
        const user = contact?.user;
        return (user ? (get(user, 'status') ?? get(user, 'session_status')) : null) ?? 'active';
    }

    hasInactiveLogin(contact) {
        return ['inactive', 'disabled', 'suspended'].includes(this.linkedUserStatus(contact));
    }

    isCustomerPortalInstalled() {
        return this.extensionManager.isInstalled('@fleetbase/customer-portal-engine');
    }

    accountActionButton(contact, options = {}) {
        if (!this.hasLinkedUser(contact)) {
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
                text: 'Reset Password',
                icon: 'key',
                fn: () => this.openResetPasswordModal(contact),
            },
            {
                text: 'Send Credentials',
                icon: 'paper-plane',
                fn: () => this.confirmSendCredentials(contact),
            },
            this.hasInactiveLogin(contact)
                ? {
                      text: 'Reactivate Login',
                      icon: 'unlock',
                      fn: () => this.confirmReactivatePortalLogin(contact),
                  }
                : {
                      text: 'Deactivate Login',
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
                    text: 'Convert to Vendor',
                    icon: 'building',
                    fn: () => this.openConvertToVendorModal(contact, options),
                }
            );
        }

        return actions;
    }

    accountRowActionItems(options = {}) {
        const hasCustomerPortal = this.isCustomerPortalInstalled();
        const hasLinkedUser = (contact) => this.hasLinkedUser(contact);

        const actions = [
            {
                label: 'Reset Password',
                icon: 'key',
                fn: (contact) => this.openResetPasswordModal(contact),
                isVisible: hasLinkedUser,
            },
            {
                label: 'Send Credentials',
                icon: 'paper-plane',
                fn: (contact) => this.confirmSendCredentials(contact),
                isVisible: hasLinkedUser,
            },
            {
                label: 'Deactivate Login',
                icon: 'lock',
                class: 'text-red-500 hover:text-red-600',
                fn: (contact) => this.confirmDeactivatePortalLogin(contact),
                isVisible: (contact) => hasLinkedUser(contact) && !this.hasInactiveLogin(contact),
            },
            {
                label: 'Reactivate Login',
                icon: 'unlock',
                fn: (contact) => this.confirmReactivatePortalLogin(contact),
                isVisible: (contact) => hasLinkedUser(contact) && this.hasInactiveLogin(contact),
            },
        ];

        if (hasCustomerPortal) {
            actions.push({
                label: 'Convert to Vendor',
                icon: 'building',
                fn: (contact) => this.openConvertToVendorModal(contact, options),
                isVisible: hasLinkedUser,
            });
        }

        return actions;
    }

    openResetPasswordModal(contact) {
        this.modalsManager.show('modals/reset-customer-credentials', {
            customer: contact,
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
            title: 'Send Portal Credentials',
            body: 'Generate a new temporary password and email this contact their portal credentials.',
            acceptButtonText: 'Send Credentials',
            endpoint: 'customers/send-credentials',
            successMessage: 'Portal credentials sent.',
        });
    }

    confirmDeactivatePortalLogin(contact) {
        this.confirmLoginAction(contact, {
            title: 'Deactivate Login',
            body: 'Deactivate portal access for this contact. Their profile and history will be preserved.',
            acceptButtonText: 'Deactivate Login',
            acceptButtonScheme: 'danger',
            endpoint: 'customers/deactivate-portal-login',
            successMessage: 'Portal login deactivated.',
        });
    }

    confirmReactivatePortalLogin(contact) {
        this.confirmLoginAction(contact, {
            title: 'Reactivate Login',
            body: 'Reactivate portal access for this contact.',
            acceptButtonText: 'Reactivate Login',
            endpoint: 'customers/reactivate-portal-login',
            successMessage: 'Portal login reactivated.',
        });
    }

    confirmLoginAction(contact, { title, body, acceptButtonText, acceptButtonScheme, endpoint, successMessage }) {
        this.modalsManager.confirm({
            title,
            body,
            acceptButtonText,
            acceptButtonScheme,
            confirm: async (modal) => {
                modal.startLoading();

                try {
                    const response = await this.fetch.post(endpoint, { customer: contact?.id }, { namespace: INTERNAL_NAMESPACE });
                    await this.updateContactFromResponse(contact, response);
                    this.notifications.success(successMessage);
                    modal.done();
                } catch (error) {
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
        });
    }

    async updateContactFromResponse(contact, response) {
        const payload = response.customer ?? response.contact;

        if (!payload || typeof contact?.setProperties !== 'function') {
            return;
        }

        contact.setProperties(
            this.withoutIdentityFields({
                user_uuid: payload.user_uuid,
            })
        );

        if (payload.user) {
            const user = await contact.user;

            if (typeof user?.setProperties === 'function') {
                user.setProperties(this.withoutIdentityFields(payload.user));
            }
        }
    }

    withoutIdentityFields(payload = {}) {
        const attributes = { ...payload };

        delete attributes.id;
        delete attributes.uuid;
        delete attributes.public_id;

        return attributes;
    }
}
