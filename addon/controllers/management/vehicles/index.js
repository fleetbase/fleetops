import ManagementController from '../../management';
import { inject as controller } from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { isBlank } from '@ember/utils';
import { timeout } from 'ember-concurrency';
import { task } from 'ember-concurrency-decorators';

export default class ManagementVehiclesIndexController extends ManagementController {
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
     * Inject the `crud` service
     *
     * @var {Service}
     */
    @service crud;

    /**
     * Inject the `filters` service
     *
     * @var {Service}
     */
    @service filters;

    /**
     * Inject the `currentUser` service
     *
     * @var {Service}
     */
    @service currentUser;

    /**
     * Inject the `hostRouter` service
     *
     * @var {Service}
     */
    @service hostRouter;

    /**
     * Default query parameters for management controllers.
     *
     * @var {Array}
     */
    queryParams = [
        'page',
        'limit',
        'sort',
        'query',
        'public_id',
        'status',
        'created_at',
        'updated_at',
        'created_by',
        'updated_by',
        'name',
        'plate_number',
        'year',
        'vehicle_make',
        'vehicle_model',
        'display_name',
    ];

    /**
     * The search query.
     *
     * @var {String}
     */
    @tracked query = null;

    /**
     * The current page.
     *
     * @var {Integer}
     */
    @tracked page = 1;

    /**
     * The number of results to query.
     *
     * @var {Integer}
     */
    @tracked limit;

    /**
     * The param to sort the data on, the param with prepended `-` is descending.
     *
     * @var {String}
     */
    @tracked sort = '-created_at';

    /**
     * The filterable param `public_id`.
     *
     * @var {String}
     */
    @tracked public_id;

    /**
     * The filterable param `status`.
     *
     * @var {String|Array}
     */
    @tracked status;

    /**
     * The filterable param `make`.
     *
     * @var {String}
     */
    @tracked name;

    /**
     * The filterable param `plate_number`.
     *
     * @var {String}
     */
    @tracked plate_number;

    /**
     * The filterable param `vehicle_make`.
     *
     * @var {String}
     */
    @tracked vehicle_make;

    /**
     * The filterable param `vehicle_model`.
     *
     * @var {String}
     */
    @tracked vehicle_model;

    /**
     * The filterable param `year`.
     *
     * @var {String}
     */
    @tracked year;

    /**
     * The filterable param `country`.
     *
     * @var {String}
     */
    @tracked country;

    /**
     * The filterable param `fleet`.
     *
     * @var {String}
     */
    @tracked fleet;

    /**
     * The filterable param `vendor`.
     *
     * @var {String}
     */
    @tracked vendor;

    /**
     * The filterable param `driver`.
     *
     * @var {String}
     */
    @tracked driver;

    /**
     * The filterable param `display_name`.
     *
     * @var {String}
     */
    @tracked display_name;

    /**
     * TableComponent instance.
     *
     * @var {TableComponent}
     */
    @tracked table;

    /**
     * Inject the `management.drivers.index` controller
     *
     * @var {Controller}
     */
    @controller('management.drivers.index') drivers;

    /**
     * All possible order status options.
     *
     * @var {String}
     */
    @tracked statusOptions = [];

    /**
     * All columns applicable for orders
     *
     * @var {Array}
     */
    @tracked columns = [
        {
            label: 'Name',
            valuePath: 'display_name',
            photoPath: 'avatar_url',
            width: '200px',
            cellComponent: 'table/cell/vehicle-name',
            action: this.viewVehicle,
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/string',
            filterParam: 'display_name',
            showOnlineIndicator: true,
        },
        {
            label: 'Plate Number',
            valuePath: 'plate_number',
            cellComponent: 'table/cell/base',
            width: '100px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/string',
            filterParam: 'plate_number',
        },
        {
            label: 'Driver Assigned',
            cellComponent: 'table/cell/anchor',
            action: async (vehicle) => {
                const driver = await vehicle.loadDriver();

                return this.drivers.viewDriver(driver);
            },
            valuePath: 'driver_name',
            width: '120px',
            resizable: true,
            filterable: true,
            filterComponent: 'filter/model',
            filterComponentPlaceholder: 'Select driver to filter by',
            filterParam: 'driver',
            model: 'driver',
        },
        {
            label: 'ID',
            valuePath: 'public_id',
            cellComponent: 'click-to-copy',
            width: '120px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/string',
        },
        {
            label: 'Make',
            valuePath: 'make',
            cellComponent: 'table/cell/base',
            width: '80px',
            resizable: true,
            sortable: true,
            hidden: true,
            filterable: true,
            filterParam: 'make',
            filterComponent: 'filter/string',
        },
        {
            label: 'Model',
            valuePath: 'model',
            cellComponent: 'table/cell/base',
            width: '80px',
            resizable: true,
            sortable: true,
            hidden: true,
            filterable: true,
            filterParam: 'model',
            filterComponent: 'filter/string',
        },
        {
            label: 'Year',
            valuePath: 'year',
            cellComponent: 'table/cell/base',
            width: '80px',
            resizable: true,
            sortable: true,
            hidden: true,
            filterable: true,
            filterComponent: 'filter/string',
        },
        {
            label: 'Vendor',
            cellComponent: 'table/cell/anchor',
            action: async ({ vendor_uuid }) => {
                const vendor = await this.store.findRecord('vendor', vendor_uuid);

                this.vendors.viewVendor(vendor);
            },
            valuePath: 'vendor_name',
            width: '150px',
            hidden: true,
            resizable: true,
            filterable: true,
            filterComponent: 'filter/model',
            filterComponentPlaceholder: 'Select vendor to filter by',
            filterParam: 'vendor',
            model: 'vendor',
        },
        {
            label: 'Status',
            valuePath: 'status',
            cellComponent: 'table/cell/status',
            width: '10%',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/multi-option',
            filterFetchOptions: 'vehicles/statuses',
        },
        {
            label: 'Created At',
            valuePath: 'createdAt',
            sortParam: 'created_at',
            width: '120px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterParam: 'created_at',
            filterLabel: 'Created Between',
            filterComponent: 'filter/date',
        },
        {
            label: 'Updated At',
            valuePath: 'updatedAt',
            sortParam: 'updated_at',
            width: '12%',
            resizable: true,
            sortable: true,
            hidden: true,
            filterParam: 'updated_at',
            filterLabel: 'Last Updated Between',
            filterable: true,
            filterComponent: 'filter/date',
        },
        {
            label: '',
            cellComponent: 'table/cell/dropdown',
            ddButtonText: false,
            ddButtonIcon: 'ellipsis-h',
            ddButtonIconPrefix: 'fas',
            ddMenuLabel: 'Vehicle Actions',
            cellClassNames: 'overflow-visible',
            wrapperClass: 'flex items-center justify-end mx-2',
            width: '90px',
            actions: [
                {
                    label: 'View vehicle details...',
                    fn: this.viewVehicle,
                },
                {
                    label: 'Edit vehicle...',
                    fn: this.editVehicle,
                },
                {
                    separator: true,
                },
                // {
                //     label: 'Assign driver to vehicle...',
                //     fn: this.assignDriver,
                // },
                {
                    label: 'Delete vehicle...',
                    fn: this.deleteVehicle,
                },
            ],
            sortable: false,
            filterable: false,
            resizable: false,
            searchable: false,
        },
    ];

    /**
     * Reload layout view.
     */
    @action reload() {
        return this.hostRouter.refresh();
    }

    /**
     * Bulk deletes selected `vehicle` via confirm prompt
     *
     * @param {Array} selected an array of selected models
     * @void
     */
    @action bulkDeleteVehicles() {
        const selectedRows = this.table.selectedRows;

        this.crud.bulkDelete(selectedRows, {
            modelNamePath: `display_name`,
            acceptButtonText: 'Delete Vehicles',
            onSuccess: () => {
                return this.hostRouter.refresh();
            },
        });
    }

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
     * Toggles dialog to export `vehicles`
     *
     * @void
     */
    @action exportVehicles() {
        this.crud.export('vehicle');
    }

    /**
     * View a `vehicle` details in modal
     *
     * @param {VehicleModel} vehicle
     * @param {Object} options
     * @void
     */
    @action viewVehicle(vehicle) {
        return this.transitionToRoute('management.vehicles.index.details', vehicle);
    }

    /**
     * Create a new `vehicle` in modal
     *
     * @param {Object} options
     * @void
     */
    @action createVehicle() {
        return this.transitionToRoute('management.vehicles.index.new');
    }

    /**
     * Edit a `vehicle` details
     *
     * @param {VehicleModel} vehicle
     * @param {Object} options
     * @void
     */
    @action editVehicle(vehicle) {
        return this.transitionToRoute('management.vehicles.index.edit', vehicle);
    }

    /**
     * Delete a `vehicle` via confirm prompt
     *
     * @param {VehicleModel} vehicle
     * @param {Object} options
     * @void
     */
    @action deleteVehicle(vehicle, options = {}) {
        this.crud.delete(vehicle, {
            onSuccess: () => {
                return this.hostRouter.refresh();
            },
            ...options,
        });
    }

    /**
     * Allow user to assign driver to a `vehicle` via prompt
     *
     * @param {VehicleModel} vehicle
     * @param {Object} options
     * @todo implement
     * @void
     */
    @action assignDriver() {}

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
}
