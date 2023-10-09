import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { isBlank } from '@ember/object';
import { timeout } from 'ember-concurrency';
import { task } from 'ember-concurrency-decorators';

export default class ManagementIssuesIndexController extends Controller {
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
     * Queryable parameters for this controller's model
     *
     * @var {Array}
     */
    queryParams = [
        'page',
        'limit',
        'sort',
        'query',
        'status',
        'public_id',
        'internal_id',
        'payload',
        'tracking_number',
        'facilitator',
        'customer',
        'driver',
        'pickup',
        'dropoff',
        'created_by',
        'updated_by',
        'status',
    ];

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
     * The filterable param `tracking` for `tracking_number`
     *
     * @var {String}
     */
    @tracked tracking;

    /**
     * The filterable param `facilitator`
     *
     * @var {String}
     */
    @tracked facilitator;

    /**
     * The filterable param `customer`
     *
     * @var {String}
     */
    @tracked customer;

    /**
     * The filterable param `driver`
     *
     * @var {String}
     */
    @tracked driver;

    /**
     * The filterable param `payload`
     *
     * @var {String}
     */
    @tracked payload;

    /**
     * The filterable param `pickup`
     *
     * @var {String}
     */
    @tracked pickup;

    /**
     * The filterable param `dropoff`
     *
     * @var {String}
     */
    @tracked dropoff;

    /**
     * The filterable param `updated_by`
     *
     * @var {String}
     */
    @tracked updated_by;

    /**
     * The filterable param `created_by`
     *
     * @var {String}
     */
    @tracked created_by;

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
            label: 'ID',
            valuePath: 'public_id',
            width: '120px',
            resizable: true,
            sortable: true,
        },
        {
            label: 'Type',
            valuePath: 'type',
            width: '100px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/string',
        },
        {
            label: 'Reporter',
            valuePath: 'reporter.name',
            width: '120px',
            resizable: true,
            sortable: true,
        },
        {
            label: 'Assignee',
            valuePath: 'reporter.name',
            width: '120px',
            resizable: true,
            sortable: true,
        },
        {
            label: 'Vehicle',
            valuePath: 'vehicle.name',
            width: '120px',
            resizable: true,
            sortable: true,
        },
        {
            label: 'Report',
            valuePath: 'report',
            width: '120px',
            resizable: true,
            sortable: true,
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
            ddMenuLabel: 'Fuel Report Actions',
            cellClassNames: 'overflow-visible',
            width: '150px',
            actions: [
                {
                    label: 'View Details',
                    // fn: this.viewFuelReport,
                },
                {
                    label: 'Edit Issue',
                    // fn: this.editFuelReport,
                },
                {
                    separator: true,
                },
                {
                    label: 'Delete Issue',
                    // fn: this.deleteFuelReport,
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
    @action bulkDeleteIssues() {
        const selected = this.table.selectedRows;

        this.crud.bulkDelete(selected, {
            modelNamePath: `name`,
            acceptButtonText: 'Delete Issues',
            onSuccess: () => {
                return this.hostRouter.refresh();
            },
        });
    }

    /**
     * Toggles dialog to export `issue`
     *
     * @void
     */
    @action exportIssues() {
        this.crud.export('issue');
    }
}
