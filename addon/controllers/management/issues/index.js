import BaseController from '@fleetbase/fleetops-engine/controllers/base-controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { isBlank } from '@ember/object';
import { timeout } from 'ember-concurrency';
import { task } from 'ember-concurrency-decorators';
import getIssueTypes from '../../../utils/get-issue-types';
import getIssueCategories from '../../../utils/get-issue-categories';

export default class ManagementIssuesIndexController extends BaseController {
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
     * Inject the `intl` service
     *
     * @var intl
     */
    @service intl;

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
        'public_id',
        'issue_id',
        'driver',
        'vehicle',
        'assignee',
        'reporter',
        'created_by',
        'updated_by',
        'status',
        'priority',
        'cateogry',
        'type',
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
     * The filterable param `status`
     *
     * @var {String}
     */
    @tracked status;

    /**
     * The filterable param `priority`
     *
     * @var {Array|String}
     */
    @tracked priority;

    /**
     * The filterable param `type`
     *
     * @var {String}
     */
    @tracked type;

    /**
     * The filterable param `category`
     *
     * @var {String}
     */
    @tracked category;

    /**
     * The filterable param `vehicle`
     *
     * @var {String}
     */
    @tracked vehicle;

    /**
     * The filterable param `driver`
     *
     * @var {String}
     */
    @tracked driver;

    /**
     * The filterable param `assignee`
     *
     * @var {String}
     */
    @tracked assignee;

    /**
     * The filterable param `reporter`
     *
     * @var {String}
     */
    @tracked reporter;

    /**
     * All columns applicable for orders
     *
     * @var {Array}
     */
    @tracked columns = [
        {
            label: this.intl.t('fleet-ops.common.id'),
            valuePath: 'public_id',
            cellComponent: 'table/cell/anchor',
            action: this.viewIssue,
            width: '110px',
            resizable: true,
            sortable: true,
        },
        {
            label: this.intl.t('fleet-ops.common.priority'),
            valuePath: 'priority',
            cellComponent: 'table/cell/status',
            width: '100px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/multi-option',
            filterOptions: ['low', 'medium', 'high', 'critical', 'scheduled-maintenance', 'operational-suggestion'],
        },
        {
            label: this.intl.t('fleet-ops.common.type'),
            valuePath: 'type',
            width: '100px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/select',
            filterOptions: getIssueTypes(),
            placeholder: 'Select issue type',
        },
        {
            label: this.intl.t('fleet-ops.common.category'),
            valuePath: 'category',
            width: '120px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/select',
            filterOptions: getIssueCategories(),
            placeholder: 'Select issue category',
        },
        {
            label: this.intl.t('fleet-ops.common.reporter'),
            valuePath: 'reporter_name',
            width: '100px',
            cellComponent: 'table/cell/anchor',
            onClick: async (issue) => {
                let reporter = await issue.loadDReporter();

                if (reporter) {
                    this.contextPanel.focus(reporter);
                }
            },
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/model',
            filterComponentPlaceholder: 'Select reporter',
            filterParam: 'reporter',
            model: 'user',
        },
        {
            label: this.intl.t('fleet-ops.common.assignee'),
            valuePath: 'assignee_name',
            width: '100px',
            cellComponent: 'table/cell/anchor',
            onClick: async (issue) => {
                let assignee = await issue.loadDAssignee();

                if (assignee) {
                    this.contextPanel.focus(assignee);
                }
            },
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/model',
            filterComponentPlaceholder: 'Select assignee',
            filterParam: 'assignee',
            model: 'user',
        },
        {
            label: this.intl.t('fleet-ops.common.driver'),
            valuePath: 'driver_name',
            width: '100px',
            cellComponent: 'table/cell/anchor',
            onClick: async (issue) => {
                let driver = await issue.loadDriver();

                if (driver) {
                    this.contextPanel.focus(driver);
                }
            },
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/model',
            filterComponentPlaceholder: 'Select driver',
            filterParam: 'driver',
            model: 'driver',
        },
        {
            label: this.intl.t('fleet-ops.common.vehicle'),
            valuePath: 'vehicle_name',
            width: '100px',
            cellComponent: 'table/cell/anchor',
            onClick: async (issue) => {
                let vehicle = await issue.loadVehicle();

                if (vehicle) {
                    this.contextPanel.focus(vehicle);
                }
            },
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/model',
            filterComponentPlaceholder: 'Select vehicle',
            filterParam: 'vehicle',
            model: 'vehicle',
            modelNamePath: 'displayName',
        },
        {
            label: this.intl.t('fleet-ops.common.status'),
            valuePath: 'status',
            cellComponent: 'table/cell/status',
            width: '120px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/multi-option',
            filterOptions: ['pending', 'in-progress', 'backlogged', 'requires-update', 'in-review', 're-opened', 'duplicate', 'pending-review', 'escalated', 'completed', 'canceled'],
        },
        {
            label: this.intl.t('fleet-ops.common.created-at'),
            valuePath: 'createdAt',
            sortParam: 'created_at',
            width: '120px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/date',
        },
        {
            label: this.intl.t('fleet-ops.common.updated-at'),
            valuePath: 'updatedAt',
            sortParam: 'updated_at',
            width: '120px',
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
            ddMenuLabel: 'Issue Actions',
            cellClassNames: 'overflow-visible',
            wrapperClass: 'flex items-center justify-end mx-2',
            width: '10%',
            actions: [
                {
                    label: this.intl.t('fleet-ops.management.issues.index.view'),
                    fn: this.viewIssue,
                },
                {
                    label: this.intl.t('fleet-ops.management.issues.index.edit-issues'),
                    fn: this.editIssue,
                },
                {
                    separator: true,
                },
                {
                    label: this.intl.t('fleet-ops.management.issues.index.delete'),
                    fn: this.deleteIssue,
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
     * Toggles dialog to export a issue
     *
     * @void
     */
    @action exportIssues() {
        this.crud.export('issue');
    }

    /**
     * Reload layout view.
     */
    @action reload() {
        return this.hostRouter.refresh();
    }

    /**
     * View the selected issue
     *
     * @param {IssueModel} issue
     * @param {Object} options
     * @void
     */
    @action viewIssue(issue) {
        return this.transitionToRoute('management.issues.index.details', issue);
    }

    /**
     * Create a new `issue` in modal
     *
     * @void
     */
    @action createIssue() {
        return this.transitionToRoute('management.issues.index.new');
    }

    /**
     * Edit a `issue` details
     *
     * @param {IssueModel} issue
     * @void
     */
    @action editIssue(issue) {
        return this.transitionToRoute('management.issues.index.edit', issue);
    }

    /**
     * Delete a `issue` via confirm prompt
     *
     * @param {IssueModel} issue
     * @param {Object} options
     * @void
     */
    @action deleteIssue(issue, options = {}) {
        this.crud.delete(issue, {
            acceptButtonIcon: 'trash',
            onConfirm: () => {
                this.hostRouter.refresh();
            },
            ...options,
        });
    }

    /**
     * Bulk deletes selected `issues` via confirm prompt
     *
     * @param {Array} selected an array of selected models
     * @void
     */
    @action bulkDeleteIssues() {
        const selected = this.table.selectedRows;

        this.crud.bulkDelete(selected, {
            modelNamePath: `name`,
            acceptButtonText: this.intl.t('fleet-ops.management.issues.index.delete-button'),
            onSuccess: () => {
                return this.hostRouter.refresh();
            },
        });
    }
}
