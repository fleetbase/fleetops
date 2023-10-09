import Controller, { inject as controller } from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { isBlank } from '@ember/utils';
import { isArray } from '@ember/array';
import { capitalize } from '@ember/string';
import { timeout } from 'ember-concurrency';
import { task } from 'ember-concurrency-decorators';
import apiUrl from '@fleetbase/ember-core/utils/api-url';

export default class ManagementVendorsIndexController extends Controller {
    /**
     * Inject the `management.places.index` controller
     *
     * @var {Controller}
     */
    @controller('management.places.index') places;

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
     * Inject the `crud` service
     *
     * @var {Service}
     */
    @service crud;

    /**
     * Inject the `store` service
     *
     * @var {Service}
     */
    @service store;

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
     * Inject the `fetch` service
     *
     * @var {Service}
     */
    @service fetch;

    /**
     * Queryable parameters for this controller's model
     *
     * @var {Array}
     */
    queryParams = ['page', 'limit', 'sort', 'query', 'public_id', 'internal_id', 'created_by', 'updated_by', 'status'];

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
     * The filterable param `status`
     *
     * @var {Array}
     */
    @tracked status;

    /**
     * Rows for the table
     *
     * @var {Array}
     */
    @tracked rows = [];

    /**
     * All columns for the table
     *
     * @var {Array}
     */
    @tracked columns = [
        {
            label: 'Name',
            valuePath: 'name',
            width: '180px',
            cellComponent: 'table/cell/media-name',
            action: this.viewVendor,
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/string',
        },
        {
            label: 'ID',
            valuePath: 'public_id',
            cellComponent: 'click-to-copy',
            width: '110px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/string',
        },
        {
            label: 'Internal ID',
            valuePath: 'internal_id',
            cellComponent: 'click-to-copy',
            width: '100px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/string',
        },
        {
            label: 'Email',
            valuePath: 'email',
            cellComponent: 'click-to-copy',
            width: '80px',
            resizable: true,
            sortable: true,
            hidden: true,
            filterable: true,
            filterComponent: 'filter/string',
        },
        {
            label: 'Phone',
            valuePath: 'phone',
            cellComponent: 'click-to-copy',
            width: '80px',
            resizable: true,
            sortable: true,
            hidden: true,
            filterable: true,
            filterComponent: 'filter/string',
        },
        {
            label: 'Address',
            valuePath: 'address',
            cellComponent: 'table/cell/anchor',
            action: this.viewVendorPlace,
            width: '150px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterParam: 'address',
            filterComponent: 'filter/string',
        },
        {
            label: 'Type',
            valuePath: 'type',
            cellComponent: 'table/cell/status',
            width: '120px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/string',
        },
        {
            label: 'Country',
            valuePath: 'country',
            cellComponent: 'table/cell/base',
            cellClassNames: 'uppercase',
            width: '130px',
            resizable: true,
            sortable: true,
            hidden: true,
            filterable: true,
            filterComponent: 'filter/string',
        },
        {
            label: 'Created At',
            valuePath: 'createdAt',
            sortParam: 'created_at',
            width: '150px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/date',
        },
        {
            label: 'Updated At',
            valuePath: 'updatedAt',
            sortParam: 'updated_at',
            width: '130px',
            resizable: true,
            sortable: true,
            hidden: true,
            filterable: true,
            filterComponent: 'filter/date',
        },
        {
            label: 'Status',
            valuePath: 'status',
            cellComponent: 'table/cell/status',
            width: '130px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/multi-option',
            filterOptions: this.statusOptions,
        },
        {
            label: '',
            cellComponent: 'table/cell/dropdown',
            ddButtonText: false,
            ddButtonIcon: 'ellipsis-h',
            ddButtonIconPrefix: 'fas',
            ddMenuLabel: 'Vendor Actions',
            cellClassNames: 'overflow-visible',
            wrapperClass: 'flex items-center justify-end mx-2',
            width: '7%',
            actions: [
                {
                    label: 'View Vendor Details',
                    fn: this.viewVendor,
                },
                {
                    label: 'Edit Vendor',
                    fn: this.editVendor,
                },
                {
                    separator: true,
                },
                {
                    label: 'Delete Vendor',
                    fn: this.deleteVendor,
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
     * Bulk deletes selected `driver` via confirm prompt
     *
     * @param {Array} selected an array of selected models
     * @void
     */
    @action bulkDeleteVendors() {
        const selected = this.table.selectedRows;

        this.crud.bulkDelete(selected, {
            modelNamePath: `name`,
            acceptButtonText: 'Delete Vendors',
            onSuccess: () => {
                return this.hostRouter.refresh();
            },
        });
    }

    /**
     * Toggles dialog to export `vendor`
     *
     * @void
     */
    @action exportVendors() {
        this.crud.export('vendor');
    }

    /**
     * View a `vendor` details in modal
     *
     * @param {VendorModel} vendor
     * @param {Object} options
     * @void
     */
    @action viewVendor(vendor, options) {
        const isIntegratedVendor = vendor.get('type') === 'integrated-vendor';

        this.modalsManager.show('modals/vendor-details', {
            title: vendor.name,
            titleComponent: 'modal/title-with-buttons',
            acceptButtonText: 'Done',
            hideDeclineButton: true,
            headerButtons: [
                {
                    icon: 'cog',
                    iconPrefix: 'fas',
                    type: 'link',
                    size: 'xs',
                    options: [
                        {
                            title: 'Edit Vendor',
                            action: () => {
                                this.modalsManager.done().then(() => {
                                    return this.editVendor(vendor, {
                                        onFinish: () => {
                                            this.viewVendor(vendor);
                                        },
                                    });
                                });
                            },
                        },
                        {
                            title: 'Delete Vendor',
                            action: () => {
                                this.modalsManager.done().then(() => {
                                    return this.deleteVendor(vendor, {
                                        onDecline: () => {
                                            this.viewVendor(vendor);
                                        },
                                    });
                                });
                            },
                        },
                    ],
                },
            ],
            isIntegratedVendor,
            vendor,
            ...options,
        });
    }

    /**
     * Create a new `vendor` in modal
     *
     * @param {Object} options
     * @void
     */
    @action async createVendor() {
        const vendor = this.store.createRecord('vendor', { status: 'active' });
        const supportedIntegratedVendors = await this.fetch.get('integrated-vendors/supported');

        return this.editVendor(vendor, {
            title: 'New Vendor',
            acceptButtonText: 'Confirm & Create',
            acceptButtonIcon: 'check',
            acceptButtonIconPrefix: 'fas',
            action: 'select',
            supportedIntegratedVendors,
            selectedIntegratedVendor: null,
            integratedVendor: null,
            selectIntegratedVendor: (integratedVendor) => {
                this.modalsManager.setOption('selectedIntegratedVendor', integratedVendor);

                const { credential_params, option_params } = integratedVendor;

                // create credentials object
                const credentials = {};
                if (isArray(integratedVendor.credential_params)) {
                    for (let i = 0; i < integratedVendor.credential_params.length; i++) {
                        const param = integratedVendor.credential_params.objectAt(i);
                        credentials[param] = null;
                    }
                }

                // create options object
                const options = {};
                if (isArray(integratedVendor.option_params)) {
                    for (let i = 0; i < integratedVendor.option_params.length; i++) {
                        const param = integratedVendor.option_params.objectAt(i);
                        options[param.key] = null;
                    }
                }

                const vendor = this.store.createRecord('integrated-vendor', {
                    provider: integratedVendor.code,
                    webhook_url: apiUrl(`listeners/${integratedVendor.code}`),
                    credentials: {},
                    options: {},
                    credential_params,
                    option_params,
                });

                this.modalsManager.setOption('integratedVendor', vendor);
            },
            successNotification: (vendor) => `New vendor '${vendor.name}' successfully created.`,
        });
    }

    /**
     * Edit a `vendor` details
     *
     * @param {VendorModel} vendor
     * @param {Object} options
     * @void
     */
    @action editVendor(vendor, options = {}) {
        const editVendorOptions = options;
        const isIntegratedVendor = vendor.get('type') === 'integrated-vendor';

        this.modalsManager.show('modals/vendor-form', {
            title: isIntegratedVendor ? 'Integrated Vendor Settings' : 'Edit Vendor',
            acceptButtonText: 'Save Changes',
            acceptButtonIcon: 'save',
            declineButtonIcon: 'times',
            declineButtonIconPrefix: 'fas',
            isIntegratedVendor,
            vendor,
            showAdvancedOptions: false,
            isEditingCredentials: false,
            toggleCredentialsReset: () => {
                const isEditingCredentials = this.modalsManager.getOption('isEditingCredentials');

                if (isEditingCredentials) {
                    this.modalsManager.setOption('isEditingCredentials', false);
                } else {
                    this.modalsManager.setOption('isEditingCredentials', true);
                }
            },
            toggleAdvancedOptions: () => {
                const showAdvancedOptions = this.modalsManager.getOption('showAdvancedOptions');

                if (showAdvancedOptions) {
                    this.modalsManager.setOption('showAdvancedOptions', false);
                } else {
                    this.modalsManager.setOption('showAdvancedOptions', true);
                }
            },
            selectAddress: (place) => {
                vendor.setProperties({
                    place_uuid: place.id,
                    place: place,
                    country: place.country,
                });
            },
            editAddress: () => {
                return this.editVendorPlace(vendor, {
                    onFinish: () => {
                        this.editVendor(vendor, editVendorOptions);
                    },
                });
            },
            newAddress: () => {
                return this.createVendorPlace(vendor, {
                    onConfirm: (place) => {
                        vendor.set('place_uuid', place.id);
                        vendor.save();
                    },
                    onFinish: () => {
                        this.modalsManager.done().then(() => {
                            this.editVendor(vendor, editVendorOptions);
                        });
                    },
                });
            },
            confirm: (modal) => {
                modal.startLoading();

                const isAddingIntegratedVendor = modal.getOption('action') !== undefined && modal.getOption('integratedVendor')?.isNew;

                if (isAddingIntegratedVendor) {
                    const integratedVendor = modal.getOption('integratedVendor');

                    return integratedVendor
                        .save()
                        .then((integratedVendor) => {
                            this.notifications.success(`Successfully added ${capitalize(integratedVendor.provider)} new integrated vendor`);

                            return this.hostRouter.refresh();
                        })
                        .catch((error) => {
                            this.notifications.serverError(error, {
                                clearDuration: 600 * 6,
                            });
                        })
                        .finally(() => {
                            modal.stopLoading();
                        });
                }

                return vendor
                    .save()
                    .then((vendor) => {
                        if (typeof options.successNotification === 'function') {
                            this.notifications.success(options.successNotification(vendor));
                        } else {
                            this.notifications.success(options.successNotification || `${vendor.name} details updated.`);
                        }

                        return this.hostRouter.refresh();
                    })
                    .catch((error) => {
                        this.notifications.serverError(error);
                    })
                    .finally(() => {
                        modal.stopLoading();
                    });
            },
            ...options,
        });
    }

    /**
     * Delete a `vendor` via confirm prompt
     *
     * @param {VendorModel} vendor
     * @param {Object} options
     * @void
     */
    @action deleteVendor(vendor, options = {}) {
        this.crud.delete(vendor, {
            acceptButtonIcon: 'trash',
            onSuccess: () => {
                return this.hostRouter.refresh();
            },
            ...options,
        });
    }

    /**
     * View information about the vendors place
     *
     * @param {VendorModel} vendor
     * @param {Object} options
     * @void
     */
    @action async viewVendorPlace(vendor, options = {}) {
        this.modalsManager.displayLoader();

        const place = await this.store.findRecord('place', vendor.place_uuid);

        this.modalsManager.done().then(() => {
            return this.places.viewPlace(place, options);
        });
    }

    /**
     * View information about the vendors place
     *
     * @param {VendorModel} vendor
     * @param {Object} options
     * @void
     */
    @action async editVendorPlace(vendor, options = {}) {
        this.modalsManager.displayLoader();

        const place = await this.store.findRecord('place', vendor.get('place_uuid'));
        await this.modalsManager.done({ skipCallbacks: true });

        this.modalsManager.done({ skipCallbacks: true }).then(() => {
            return this.places.editPlace(place, options);
        });
    }

    /**
     * View information about the vendors place
     *
     * @param {DriverModel} driver
     * @param {Object} options
     * @void
     */
    @action async createVendorPlace(vendor, options = {}) {
        this.modalsManager.displayLoader();
        await this.modalsManager.done();

        this.modalsManager.done().then(() => {
            return this.places.createPlace(options);
        });
    }
}
