import Controller, { inject as controller } from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { isBlank } from '@ember/utils';
import { task, timeout } from 'ember-concurrency';
import Point from '@fleetbase/fleetops-data/utils/geojson/point';

export default class ManagementPlacesIndexController extends Controller {
    /**
     * Inject the `operations.zones.index` controller
     *
     * @var {Controller}
     */
    @controller('operations.zones.index') zonesController;

    /**
     * Inject the `management.vendors.index` controller
     *
     * @var {Controller}
     */
    @controller('management.vendors.index') vendorsController;

    /**
     * Inject the `notifications` service
     *
     * @var {Service}
     */
    @service notifications;

    /**
     * Inject the `modals-manager` service
     *
     * @var {Service}
     */
    @service modalsManager;

    /**
     * Inject the `store` service
     *
     * @var {Service}
     */
    @service store;

    /**
     * Inject the `fetch` service
     *
     * @var {Service}
     */
    @service fetch;

    /**
     * Inject the `filters` service
     *
     * @var {Service}
     */
    @service filters;

    /**
     * Inject the `hostRouter` service
     *
     * @var {Service}
     */
    @service hostRouter;

    /**
     * Inject the `crud` service
     *
     * @var {Service}
     */
    @service crud;

    /**
     * Queryable parameters for this controller's model
     *
     * @var {Array}
     */
    queryParams = ['page', 'limit', 'sort', 'query', 'public_id', 'internal_id', 'country', 'phone', 'email', 'created_at', 'updated_at'];

    /**
     * The current page of data being viewed
     *
     * @var {Integer}
     */
    @tracked page = 1;

    /**
     * The maximum number of items to show per page
     *
     * @var {Integer}
     */
    @tracked limit;

    /**
     * The param to sort the data on, the param with prepended `-` is descending
     *
     * @var {String}
     */
    @tracked sort = '-created_at';

    /**
     * The filterable param `public_id`
     *
     * @var {String}
     */
    @tracked public_id;

    /**
     * The filterable param `internal_id`
     *
     * @var {String}
     */
    @tracked internal_id;

    /**
     * The filterable param `phone`
     *
     * @var {String}
     */
    @tracked phone;

    /**
     * The filterable param `email`
     *
     * @var {String}
     */
    @tracked email;

    /**
     * The filterable param `country`
     *
     * @var {String}
     */
    @tracked country;

    /**
     * All columns applicable for orders
     *
     * @var {Array}
     */
    @tracked columns = [
        {
            label: 'Name',
            valuePath: 'name',
            width: '200px',
            cellComponent: 'table/cell/anchor',
            action: this.viewPlace,
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/string',
        },
        {
            label: 'Address',
            valuePath: 'address',
            cellComponent: 'table/cell/anchor',
            action: this.viewPlace,
            width: '320px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterParam: 'address',
            filterComponent: 'filter/string',
        },
        {
            label: 'ID',
            valuePath: 'public_id',
            width: '120px',
            cellComponent: 'click-to-copy',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/string',
        },
        {
            label: 'Internal ID',
            valuePath: 'internal_id',
            cellComponent: 'click-to-copy',
            width: '120px',
            resizable: true,
            sortable: true,
            filterable: true,
            hidden: true,
            filterComponent: 'filter/string',
        },
        {
            label: 'Email',
            valuePath: 'email',
            cellComponent: 'table/cell/base',
            width: '120px',
            resizable: true,
            sortable: true,
            hidden: true,
            filterable: true,
            filterComponent: 'filter/string',
        },
        {
            label: 'Phone',
            valuePath: 'phone',
            cellComponent: 'table/cell/base',
            width: '120px',
            resizable: true,
            sortable: true,
            hidden: true,
            filterable: true,
            filterComponent: 'filter/string',
        },
        {
            label: 'Country',
            valuePath: 'country_name',
            cellComponent: 'table/cell/base',
            cellClassNames: 'uppercase',
            width: '120px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/country',
            filterParam: 'country',
        },
        {
            label: 'Created At',
            valuePath: 'createdAt',
            sortParam: 'created_at',
            width: '10%',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/date',
        },
        {
            label: 'Updated At',
            valuePath: 'updatedAt',
            sortParam: 'updated_at',
            width: '10%',
            resizable: true,
            sortable: true,
            hidden: true,
            filterable: true,
            filterComponent: 'filter/date',
        },
        {
            label: '',
            cellComponent: 'table/cell/dropdown',
            ddButtonText: false,
            ddButtonIcon: 'ellipsis-h',
            ddButtonIconPrefix: 'fas',
            ddMenuLabel: 'Place Actions',
            cellClassNames: 'overflow-visible',
            wrapperClass: 'flex items-center justify-end mx-2',
            width: '10%',
            actions: [
                {
                    label: 'View Place Details',
                    fn: this.viewPlace,
                },
                {
                    label: 'Edit Place',
                    fn: this.editPlace,
                },
                {
                    separator: true,
                },
                {
                    label: 'View Place on Map',
                    fn: this.viewOnMap,
                },
                {
                    separator: true,
                },
                {
                    label: 'Delete Place',
                    fn: this.deletePlace,
                },
            ],
            sortable: false,
            filterable: false,
            resizable: false,
            searchable: false,
        },
    ];

    /**
     * The search task.
     *
     * @void
     */
    @task({ restartable: true }) *search({ target: { value } }) {
        // if no query don't search
        if (isBlank(value)) {
            this.query = null;
            return;
        }

        // timeout for typing
        yield timeout(250);

        // reset page for results
        if (this.page > 1) {
            this.page = 1;
        }

        // update the query param
        this.query = value;
    }

    /**
     * Toggles dialog to export `place`
     *
     * @void
     */
    @action exportPlaces() {
        this.crud.export('place');
    }

    /**
     * View a `place` details in modal
     *
     * @param {PlaceModel} place
     * @param {Object} options
     * @void
     */
    @action viewPlace(place, options) {
        const viewPlaceOnMap = () => {
            this.modalsManager.done().then(() => {
                return this.viewOnMap(place, {
                    onFinish: () => {
                        this.viewPlace(place);
                    },
                });
            });
        };

        this.modalsManager.show('modals/place-details', {
            title: place.name,
            place,
            titleComponent: 'modal/title-with-buttons',
            acceptButtonText: 'Done',
            headerButtons: [
                {
                    icon: 'cog',
                    type: 'link',
                    size: 'xs',
                    options: [
                        {
                            title: 'Edit Place',
                            action: () => {
                                this.modalsManager.done().then(() => {
                                    return this.editPlace(place, {
                                        onFinish: () => {
                                            this.viewDriver(place);
                                        },
                                    });
                                });
                            },
                        },
                        {
                            title: 'View Place on Map',
                            action: viewPlaceOnMap,
                        },
                        {
                            title: 'Assign to Vendor',
                            action: () => {
                                this.modalsManager.done().then(() => {
                                    return this.assignVendor(place, {
                                        onFinish: () => {
                                            this.viewPlace(place);
                                        },
                                    });
                                });
                            },
                        },
                        {
                            title: 'Delete Place',
                            action: () => {
                                this.modalsManager.done().then(() => {
                                    return this.deletePlace(place, {
                                        onDecline: () => {
                                            this.viewPlace(place);
                                        },
                                    });
                                });
                            },
                        },
                    ],
                },
            ],
            viewVendor: () =>
                this.viewPlaceVendor(place, {
                    onFinish: () => {
                        this.viewPlace(place);
                    },
                }),
            viewPlaceOnMap,
            ...options,
        });
    }

    /**
     * Create a new `place` in modal
     *
     * @param {Object} options
     * @void
     */
    @action createPlace(options = {}) {
        const place = this.store.createRecord('place');

        return this.editPlace(place, {
            title: 'New Place',
            acceptButtonText: 'Confirm & Create',
            acceptButtonIcon: 'check',
            acceptButtonIconPrefix: 'fas',
            successNotification: (place) => `New place (${place.get('name') || place.get('street1')}) created.`,
            onConfirm: () => {
                if (place.get('isNew')) {
                    return;
                }

                return this.hostRouter.refresh();
            },
            ...options,
        });
    }

    /**
     * Edit a `place` details
     *
     * @param {PlaceModel} place
     * @param {Object} options
     * @void
     */
    @action async editPlace(place, options = {}) {
        place = await place;

        this.modalsManager.show('modals/place-form', {
            title: 'Edit Place',
            acceptButtonText: 'Save Changes',
            acceptButtonIcon: 'save',
            declineButtonIcon: 'times',
            declineButtonIconPrefix: 'fas',
            place,
            autocomplete: (selected) => {
                const coordinatesInputComponent = this.modalsManager.getOption('coordinatesInputComponent');

                place.setProperties({ ...selected });

                if (coordinatesInputComponent) {
                    coordinatesInputComponent.updateCoordinates(selected.location);
                }
            },
            reverseGeocode: ({ latitude, longitude }) => {
                return this.fetch.get('geocoder/reverse', { coordinates: [latitude, longitude].join(','), single: true }).then((result) => {
                    if (isBlank(result)) {
                        return;
                    }

                    place.setProperties({ ...result });
                });
            },
            setCoordinatesInput: (coordinatesInputComponent) => {
                this.modalsManager.setOption('coordinatesInputComponent', coordinatesInputComponent);
            },
            updatePlaceCoordinates: ({ latitude, longitude }) => {
                const location = new Point(longitude, latitude);

                place.setProperties({ location });
            },
            confirm: (modal) => {
                modal.startLoading();

                return place
                    .save()
                    .then((place) => {
                        if (typeof options.successNotification === 'function') {
                            this.notifications.success(options.successNotification(place));
                        } else {
                            this.notifications.success(options.successNotification ?? `${place.name} details updated.`);
                        }

                        return this.hostRouter.refresh();
                    })
                    .catch((error) => {
                        modal.stopLoading();
                        this.notifications.serverError(error);
                    });
            },
            ...options,
        });
    }

    /**
     * Delete a `place` via confirm prompt
     *
     * @param {PlaceModel} place
     * @param {Object} options
     * @void
     */
    @action deletePlace(place, options = {}) {
        this.crud.delete(place, {
            onConfirm: () => {
                return this.hostRouter.refresh();
            },
            ...options,
        });
    }

    /**
     * Bulk deletes selected `place` via confirm prompt
     *
     * @param {Array} selected an array of selected models
     * @void
     */
    @action bulkDeletePlaces() {
        const selected = this.table.selectedRows;

        this.crud.bulkDelete(selected, {
            modelNamePath: `address`,
            acceptButtonText: 'Delete Places',
            onSuccess: () => {
                return this.hostRouter.refresh();
            },
        });
    }

    /**
     * Prompt user to assign a `vendor` to a `place`
     *
     * @param {PlaceModel} place
     * @param {Object} options
     * @void
     */
    @action assignVendor(place, options = {}) {
        this.modalsManager.show('modals/place-assign-vendor', {
            title: `Assign this Place to a Vendor`,
            acceptButtonText: 'Confirm & Create',
            hideDeclineButton: true,
            place,
            confirm: (modal) => {
                modal.startLoading();
                return place.save().then(() => {
                    this.notifications.success(`'${place.name}' details has been updated.`);
                });
            },
            ...options,
        });
    }

    /**
     * View a place location on map
     *
     * @param {PlaceModel} place
     * @param {Object} options
     * @void
     */
    @action viewOnMap(place, options = {}) {
        const { latitude, longitude } = place;

        this.modalsManager.show('modals/point-map', {
            title: `Location of ${place.name}`,
            acceptButtonText: 'Done',
            hideDeclineButton: true,
            latitude,
            longitude,
            location: [latitude, longitude],
            ...options,
        });
    }

    /**
     * View information about the driver vendor
     *
     * @param {PlaceModel} place
     * @param {Object} options
     * @void
     */
    @action async viewPlaceVendor(place, options = {}) {
        this.modalsManager.displayLoader();

        const vendor = await this.store.findRecord('vendor', place.vendor_uuid);

        this.modalsManager.done().then(() => {
            return this.vendorsController.viewVendor(vendor, options);
        });
    }
}
