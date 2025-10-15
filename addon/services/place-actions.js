import ResourceActionService from '@fleetbase/ember-core/services/resource-action';
import leafletIcon from '@fleetbase/ember-core/utils/leaflet-icon';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class PlaceActionsService extends ResourceActionService {
    @service vendorActions;

    constructor() {
        super(...arguments);
        this.initialize('place', { modelNamePath: 'address' });
    }

    transition = {
        view: (place) => this.transitionTo('management.places.index.details', place),
        edit: (place) => this.transitionTo('management.places.index.edit', place),
        create: () => this.transitionTo('management.places.index.new'),
    };

    panel = {
        create: (attributes = {}) => {
            const place = this.createNewInstance(attributes);
            return this.resourceContextPanel.open({
                content: 'place/form',
                title: this.intl.t('common.create-a-new-resource', { resource: this.intl.t('resource.place')?.toLowerCase() }),
                useDefaultSaveTask: true,
                saveOptions: {
                    callback: this.refresh,
                },
                place,
            });
        },
        edit: (place) => {
            return this.resourceContextPanel.open({
                content: 'place/form',
                title: this.intl.t('common.edit-resource-name', { resourceName: place.address }),
                useDefaultSaveTask: true,
                headerLeftClass: 'w-1/2',
                titleWrapperClass: 'w-1/2',
                place,
            });
        },
        view: (place) => {
            return this.resourceContextPanel.open({
                place,
                tabs: [
                    {
                        label: this.intl.t('common.overview'),
                        component: 'place/details',
                    },
                ],
            });
        },
    };

    modal = {
        create: (attributes = {}, options = {}, saveOptions = {}) => {
            const place = this.createNewInstance(attributes);
            return this.modalsManager.show('modals/resource', {
                resource: place,
                title: this.intl.t('common.create-a-new-resource', { resource: this.intl.t('resource.place')?.toLowerCase() }),
                acceptButtonText: this.intl.t('common.create-resource', { resource: this.intl.t('resource.place') }),
                component: 'place/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', place, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        edit: (place, options = {}, saveOptions = {}) => {
            return this.modalsManager.show('modals/resource', {
                resource: place,
                title: this.intl.t('common.edit-resource-name', { resourceName: place.address }),
                acceptButtonText: this.intl.t('common.save-changes'),
                saveButtonIcon: 'save',
                component: 'place/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', place, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        view: (place, options = {}) => {
            return this.modalsManager.show('modals/resource', {
                resource: place,
                title: place.displayName,
                component: 'place/details',
                ...options,
            });
        },
    };

    @action locate(place, options = {}) {
        const { latitude, longitude, location } = place;

        return this.modalsManager.show('modals/point-map', {
            title: this.intl.t('common.resource-location', { resource: place.address }),
            acceptButtonText: this.intl.t('common.done'),
            hideDeclineButton: true,
            resource: place,
            icon: leafletIcon({
                iconUrl: place.avatar_url ?? '/engines-dist/images/building-marker.png',
                iconSize: [40, 40],
            }),
            popupText: place.address,
            tooltip: place.address,
            latitude,
            longitude,
            location,
            ...options,
        });
    }

    @action assignVendor(place, options = {}) {
        return this.modalsManager.show('modals/place-assign-vendor', {
            title: this.intl.t('fleet-ops.management.places.index.title'),
            acceptButtonText: this.intl.t('common.confirm'),
            hideDeclineButton: true,
            place,
            confirm: async (modal) => {
                modal.startLoading();
                try {
                    await place.save();
                    this.notifications.success(this.intl.t('vendor.prompts.vendor-assigned-success', { placeName: place.address }));
                    modal.done();
                } catch (err) {
                    this.notifications.serverError(err);
                } finally {
                    modal.stopLoading();
                }
            },
            ...options,
        });
    }

    @action async viewVendor(place) {
        try {
            const vendor = await this.store.findRecord('vendor', place.vendor_uuid);
            this.vendorActions.panel.view(vendor);
        } catch (err) {
            this.notifications.serverError(err);
        }
    }
}
