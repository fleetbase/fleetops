import ResourceActionService from '@fleetbase/ember-core/services/resource-action';
import leafletIcon from '@fleetbase/ember-core/utils/leaflet-icon';
import { action } from '@ember/object';

export default class PlaceActionsService extends ResourceActionService {
    constructor() {
        super(...arguments);
        this.initialize('place', { modelNamePath: 'displayName' });
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
                title: 'Create a new place',
                panelContentClass: 'px-4',
                saveOptions: {
                    callback: this.refresh,
                },
                place,
            });
        },
        edit: (place) => {
            return this.resourceContextPanel.open({
                content: 'place/form',
                title: `Edit: ${place.displayName}`,
                panelContentClass: 'px-4',
                place,
            });
        },
        view: (place) => {
            return this.resourceContextPanel.open({
                place,
                tabs: [
                    {
                        label: 'Overview',
                        component: 'place/details',
                        contentClass: 'p-4',
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
                title: 'Create a new place',
                acceptButtonText: 'Create Place',
                component: 'place/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', place, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        edit: (place, options = {}, saveOptions = {}) => {
            return this.modalsManager.show('modals/resource', {
                resource: place,
                title: `Edit: ${place.displayName}`,
                acceptButtonText: 'Save Changes',
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
            title: this.intl.t('fleet-ops.management.places.index.locate-title', { placeName: place.displayName }),
            acceptButtonText: 'Done',
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
            acceptButtonText: this.intl.t('fleet-ops.management.places.index.confirm-button'),
            hideDeclineButton: true,
            place,
            confirm: async (modal) => {
                modal.startLoading();
                try {
                    await place.save();
                    this.notifications.success(this.intl.t('fleet-ops.management.places.index.success-message', { placeName: place.name }));
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
            this.contextPanel.focus(vendor);
        } catch (err) {
            this.notifications.serverError(err);
        }
    }
}
