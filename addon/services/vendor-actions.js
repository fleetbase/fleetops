import ResourceActionService from '@fleetbase/ember-core/services/resource-action';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { normalizeProvider, buildIntegrationPayload } from '../utils/vendor-integration';

export default class VendorActionsService extends ResourceActionService {
    @service placeActions;

    constructor() {
        super(...arguments);
        this.initialize('vendor', {
            defaultAttributes: { status: 'active' },
        });
    }

    transition = {
        view: (vendor) => this.transitionTo('management.vendors.index.details', vendor),
        edit: (vendor) => this.transitionTo('management.vendors.index.edit', vendor),
        create: () => this.transitionTo('management.vendors.index.new'),
    };

    panel = {
        create: (attributes = {}) => {
            const vendor = this.createNewInstance(attributes);
            return this.resourceContextPanel.open({
                content: 'vendor/form',
                title: this.intl.t('common.create-a-new-resource', { resource: this.intl.t('resource.vendor')?.toLowerCase() }),
                useDefaultSaveTask: true,
                saveOptions: {
                    callback: this.refresh,
                },
                vendor,
            });
        },
        edit: (vendor) => {
            return this.resourceContextPanel.open({
                content: 'vendor/form',
                title: this.intl.t('common.edit-resource-name', { resourceName: vendor.name }),
                useDefaultSaveTask: true,
                vendor,
            });
        },
        view: (vendor) => {
            return this.resourceContextPanel.open({
                vendor,
                tabs: [
                    {
                        label: this.intl.t('common.overview'),
                        component: 'vendor/details',
                    },
                ],
            });
        },
    };

    modal = {
        create: (attributes = {}, options = {}, saveOptions = {}) => {
            const vendor = this.createNewInstance(attributes);
            return this.modalsManager.show('modals/resource', {
                resource: vendor,
                title: this.intl.t('common.create-a-new-resource', { resource: this.intl.t('resource.vendor')?.toLowerCase() }),
                acceptButtonText: this.intl.t('common.create-resource', { resource: this.intl.t('resource.vendor') }),
                component: 'vendor/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', vendor, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        edit: (vendor, options = {}, saveOptions = {}) => {
            return this.modalsManager.show('modals/resource', {
                resource: vendor,
                title: this.intl.t('common.edit-resource-name', { resourceName: vendor.name }),
                acceptButtonText: this.intl.t('common.save-changes'),
                saveButtonIcon: 'save',
                component: 'vendor/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', vendor, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        view: (vendor, options = {}) => {
            return this.modalsManager.show('modals/resource', {
                resource: vendor,
                title: vendor.name,
                component: 'vendor/details',
                ...options,
            });
        },
    };

    @action createVendorIntegration(provider, opts = {}) {
        const normalized = normalizeProvider(provider);
        if (!normalized) {
            this.notifications.error('Invalid vendor provider configuration.');
            return null;
        }

        const payload = buildIntegrationPayload(normalized);
        const record = this.store.createRecord('integrated-vendor', { ...payload, provider_options: provider });

        if (opts.autoSave) {
            // Return a promise if caller wants immediate persistence
            return record.save().catch((e) => {
                this.notifications.error('Failed to create integration.');
                // bubble up so caller can handle
                throw e;
            });
        }

        return record;
    }

    @action async viewPlace(vendor) {
        const place = await vendor.place;
        if (place) {
            this.placeActions.modal.view(place);
        }
    }

    @action async editPlace(vendor) {
        const place = await vendor.place;
        if (place) {
            this.placeActions.modal.edit(place);
        }
    }

    @action async createPlace(vendor) {
        return this.placeActions.modal.create(
            {},
            {},
            {
                callback: async (place) => {
                    vendor.set('place_uuid', place.id);
                    await vendor.save();
                },
            }
        );
    }
}
