import Controller, { inject as controller } from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { isBlank } from '@ember/utils';
import { timeout } from 'ember-concurrency';
import { task } from 'ember-concurrency-decorators';

export default class ManagementFuelReportsIndexController extends Controller {
    /**
     * Inject the `management.drivers.index` controller
     *
     * @var {Controller}
     */
    @controller('management.drivers.index') drivers;

    /**
     * Inject the `management.vehicles.index` controller
     *
     * @var {Controller}
     */
    @controller('management.vehicles.index') vehicles;

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
     * Inject the `hostRouter` service
     *
     * @var {Service}
     */
    @service hostRouter;

    /**
     * Inject the `filters` service
     *
     * @var {Service}
     */
    @service filters;

    /**
     * Inject the `loader` service
     *
     * @var {Service}
     */
    @service loader;

    /**
     * Queryable parameters for this controller's model
     *
     * @var {Array}
     */
    queryParams = ['page', 'limit', 'sort', 'query', 'public_id', 'internal_id', 'vehicle', 'driver', 'created_by', 'updated_by', 'status'];

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
     * The filterable param `driver`
     *
     * @var {String}
     */
    @tracked driver;

    /**
     * The filterable param `vehicle`
     *
     * @var {String}
     */
    @tracked vehicle;

    /**
     * The filterable param `status`
     *
     * @var {Array}
     */
    @tracked status;

    /**
     * All columns applicable for orders
     *
     * @var {Array}
     */
    @tracked columns = [
        {
            label: null,
            width: '20px',
            cellComponent: 'table/cell/anchor',
            anchorIcon: 'eye',
            anchorSpanClass: 'hidden',
            action: this.viewFuelReport,
        },
        {
            label: 'Driver',
            valuePath: 'driver_name',
            width: '120px',
            cellComponent: 'table/cell/anchor',
            action: this.viewDriver,
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/model',
            filterComponentPlaceholder: 'Select driver',
            filterParam: 'driver',
            model: 'driver',
        },
        {
            label: 'Vehicle',
            valuePath: 'vehicle_name',
            width: '120px',
            cellComponent: 'table/cell/anchor',
            action: this.viewVehicle,
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/model',
            filterComponentPlaceholder: 'Select vehicle',
            filterParam: 'vehicle',
            model: 'vehicle',
        },
        {
            label: 'ID',
            valuePath: 'public_id',
            width: '120px',
            cellComponent: 'table/cell/anchor',
            action: this.viewFuelReport,
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/string',
        },
        {
            label: 'Country',
            valuePath: 'country',
            cellComponent: 'table/cell/base',
            width: '100px',
            resizable: true,
            sortable: true,
            hidden: true,
            filterable: true,
            filterComponent: 'filter/string',
        },
        {
            label: 'Status',
            valuePath: 'status',
            cellComponent: 'table/cell/status',
            width: '100px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/multi-option',
            filterOptions: this.statusOptions,
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
            width: '150px',
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
            ddMenuLabel: 'Fuel Report Actions',
            cellClassNames: 'overflow-visible',
            wrapperClass: 'flex items-center justify-end mx-2',
            width: '10%',
            actions: [
                {
                    label: 'View Details',
                    fn: this.viewFuelReport,
                },
                {
                    label: 'Edit Fuel Report',
                    fn: this.editFuelReport,
                },
                {
                    separator: true,
                },
                {
                    label: 'Delete Fuel Report',
                    fn: this.deleteFuelReport,
                },
            ],
            sortable: false,
            filterable: false,
            resizable: false,
            searchable: false,
        },
    ];

    /**
     * Bulk deletes selected `driver` via confirm prompt
     *
     * @param {Array} selected an array of selected models
     * @void
     */
    @action bulkDeleteFuelReports() {
        const selected = this.table.selectedRows;

        this.crud.bulkFuelReports(selected, {
            modelNamePath: `name`,
            acceptButtonText: 'Delete Fuel Reports',
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
     * Toggles dialog to export `fuel-report`
     *
     * @void
     */
    @action exportFuelReports() {
        this.crud.export('fuel-report');
    }

    @action async viewDriver(fuelReport) {
        if (!fuelReport.driver_uuid) {
            return this.notifications.warning('No driver attributed to fuel report.');
        }

        this.loader.show('Loading...');
        const driver = await this.store.findRecord('driver', fuelReport.driver_uuid);
        this.loader.removeLoader();

        // display driver details
        this.drivers.viewDriver(driver);
    }

    @action async viewVehicle(fuelReport) {
        if (!fuelReport.vehicle_uuid) {
            return this.notifications.warning('No vehicle attributed to fuel report.');
        }

        this.loader.show('Loading...');
        const vehicle = await this.store.findRecord('vehicle', fuelReport.vehicle_uuid);
        this.loader.remove();

        // display vehicle details
        this.vehicles.viewVehicle(vehicle);
    }

    /**
     * View a `fuelReport` details in modal
     *
     * @param {FuelReportModel} fuelReport
     * @param {Object} options
     * @void
     */
    @action viewFuelReport(fuelReport, options = {}) {
        this.modalsManager.show('modals/fuel-report-details', {
            title: `Fuel Report - ${fuelReport.createdAt}`,
            hideDeclineButton: true,
            confirmButtonText: 'Done',
            fuelReport,
            ...options,
        });
    }

    /**
     * Create a new `fuelReport` in modal
     *
     * @void
     */
    @action createFuelReport() {
        const fuelReport = this.store.createRecord('fuel-report');

        return this.editFuelReport(fuelReport, {
            title: 'New Fuel Report',
            acceptButtonText: 'Confirm & Create',
            acceptButtonIcon: 'check',
            acceptButtonIconPrefix: 'fas',
            successNotification: 'New fuel report created.',
            onConfirm: () => {
                this.hostRouter.refresh();
            },
        });
    }

    /**
     * Edit a `fuelReport` details
     *
     * @param {FuelReportModel} fuelReport
     * @param {Object} options
     * @void
     */
    @action editFuelReport(fuelReport, options = {}) {
        this.modalsManager.show('modals/fuel-report-form', {
            title: 'Edit FuelReport',
            acceptButtonIcon: 'save',
            fuelReport,
            confirm: (modal, done) => {
                modal.startLoading();

                return fuelReport
                    .save()
                    .then((fuelReport) => {
                        if (typeof options.successNotification === 'function') {
                            this.notifications.success(options.successNotification(fuelReport));
                        } else {
                            this.notifications.success(options.successNotification ?? `Fuel report details updated.`);
                        }
                        return done();
                    })
                    .catch((error) => {
                        this.notifications.serverError(error);
                        modal.stopLoading();
                    });
            },
            ...options,
        });
    }

    /**
     * Delete a `fuelReport` via confirm prompt
     *
     * @param {FuelReportModel} fuelReport
     * @param {Object} options
     * @void
     */
    @action deleteFuelReport(fuelReport, options = {}) {
        this.crud.delete(fuelReport, {
            onConfirm: () => {
                this.hostRouter.refresh();
            },
            ...options,
        });
    }
}
