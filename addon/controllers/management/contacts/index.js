import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { isBlank } from '@ember/utils';
import { timeout } from 'ember-concurrency';
import { task } from 'ember-concurrency-decorators';

export default class ManagementContactsIndexController extends Controller {
    /**
     * Inject the `currentUser` service
     *
     * @var {Service}
     */
    @service store;

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
     * Inject the `filters` service
     *
     * @var {Service}
     */
    @service filters;

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
    queryParams = ['page', 'limit', 'sort', 'query', 'public_id', 'internal_id', 'created_by', 'updated_by', 'status', 'title', 'email', 'phone', 'type'];

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
     * The filterable param `title`
     *
     * @var {String}
     */
    @tracked title;

    /**
     * The filterable param `email`
     *
     * @var {String}
     */
    @tracked email;

    /**
     * The filterable param `phone`
     *
     * @var {String}
     */
    @tracked phone;

    /**
     * The filterable param `email`
     *
     * @var {Array|String}
     */
    @tracked type;

    /**
     * The filterable param `status`
     *
     * @var {Array}
     */
    @tracked status;

    /**
     * All possible contact types
     *
     * @var {String}
     */
    @tracked contactTypes = ['contact', 'customer'];

    /**
     * All columns applicable for orders
     *
     * @var {Array}
     */
    @tracked columns = [
        {
            label: 'Name',
            valuePath: 'name',
            width: '170px',
            cellComponent: 'table/cell/media-name',
            action: this.viewContact,
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/string',
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
            label: 'Internal ID',
            valuePath: 'internal_id',
            cellComponent: 'click-to-copy',
            width: '130px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/string',
        },
        {
            label: 'Title',
            valuePath: 'title',
            cellComponent: 'click-to-copy',
            width: '160px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/string',
        },
        {
            label: 'Email',
            valuePath: 'email',
            cellComponent: 'click-to-copy',
            width: '160px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/string',
        },
        {
            label: 'Phone',
            valuePath: 'phone',
            cellComponent: 'click-to-copy',
            width: '140px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/string',
        },
        {
            label: 'Type',
            valuePath: 'type',
            cellComponent: 'table/cell/status',
            width: '100px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/multi-option',
            filterOptions: ['contact', 'customer'],
        },
        {
            label: 'Created At',
            valuePath: 'createdAt',
            sortParam: 'created_at',
            width: '130px',
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
            label: '',
            cellComponent: 'table/cell/dropdown',
            ddButtonText: false,
            ddButtonIcon: 'ellipsis-h',
            ddButtonIconPrefix: 'fas',
            ddMenuLabel: 'Contact Actions',
            cellClassNames: 'overflow-visible',
            wrapperClass: 'flex items-center justify-end mx-2',
            width: '10%',
            actions: [
                {
                    label: 'View Contact Details',
                    fn: this.viewContact,
                },
                {
                    label: 'Edit Contact',
                    fn: this.editContact,
                },
                {
                    separator: true,
                },
                {
                    label: 'Delete Contact',
                    fn: this.deleteContact,
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
     * Reload layout view.
     */
    @action reload() {
        return this.hostRouter.refresh();
    }

    /**
     * Toggles dialog to export `contact`
     *
     * @void
     */
    @action exportContacts() {
        this.crud.export('contact');
    }

    /**
     * View a `contact` details in modal
     *
     * @param {ContactModel} contact
     * @void
     */
    @action viewContact(contact) {
        return this.transitionToRoute('management.contacts.index.details', contact);
    }

    /**
     * Create a new `contact` in modal
     *
     * @void
     */
    @action createContact() {
        return this.transitionToRoute('management.contacts.index.new');
    }

    /**
     * Edit a `contact` details
     *
     * @param {ContactModel} contact
     * @void
     */
    @action editContact(contact) {
        return this.transitionToRoute('management.contacts.index.edit', contact);
    }

    /**
     * Delete a `contact` via confirm prompt
     *
     * @param {ContactModel} contact
     * @param {Object} options
     * @void
     */
    @action deleteContact(contact, options = {}) {
        this.crud.delete(contact, {
            acceptButtonIcon: 'trash',
            onConfirm: () => {
                this.hostRouter.refresh();
            },
            ...options,
        });
    }

    /**
     * Bulk deletes selected `contacts` via confirm prompt
     *
     * @param {Array} selected an array of selected models
     * @void
     */
    @action bulkDeleteContacts() {
        const selected = this.table.selectedRows;

        this.crud.bulkDelete(selected, {
            modelNamePath: `name`,
            acceptButtonText: 'Delete Contacts',
            onSuccess: () => {
                return this.hostRouter.refresh();
            },
        });
    }
}
