import ResourceActionService from '@fleetbase/ember-core/services/resource-action';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { format } from 'date-fns';

export default class IssueActionsService extends ResourceActionService {
    @service driverActions;
    @service vehicleActions;

    constructor() {
        super(...arguments);
        this.initialize('issue', {
            defaultAttributes: {
                title: `Issue reported on ${format(new Date(), 'dd MMM yy, HH:mm')}`,
                status: 'pending',
                priority: 'low',
                type: 'operational',
            },
        });
    }

    transition = {
        view: (issue) => this.transitionTo('management.issues.index.details', issue),
        edit: (issue) => this.transitionTo('management.issues.index.edit', issue),
        create: () => this.transitionTo('management.issues.index.new'),
    };

    panel = {
        create: (attributes = {}) => {
            const issue = this.createNewInstance(attributes);
            return this.resourceContextPanel.open({
                content: 'issue/form',
                title: 'Create a new issue',

                saveOptions: {
                    callback: this.refresh,
                },
                issue,
            });
        },
        edit: (issue) => {
            return this.resourceContextPanel.open({
                content: 'issue/form',
                title: `Edit: ${issue.name}`,

                issue,
            });
        },
        view: (issue) => {
            return this.resourceContextPanel.open({
                issue,
                tabs: [
                    {
                        label: 'Overview',
                        component: 'issue/details',
                    },
                ],
            });
        },
    };

    modal = {
        create: (attributes = {}, options = {}, saveOptions = {}) => {
            const issue = this.createNewInstance(attributes);
            return this.modalsManager.show('modals/resource', {
                resource: issue,
                title: 'Create a new issue',
                acceptButtonText: 'Create Issue',
                component: 'issue/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', issue, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        edit: (issue, options = {}, saveOptions = {}) => {
            return this.modalsManager.show('modals/resource', {
                resource: issue,
                title: `Edit: ${issue.name}`,
                acceptButtonText: 'Save Changes',
                saveButtonIcon: 'save',
                component: 'issue/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', issue, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        view: (issue, options = {}) => {
            return this.modalsManager.show('modals/resource', {
                resource: issue,
                title: issue.name,
                component: 'issue/details',
                ...options,
            });
        },
    };

    @action async viewDriver(issue) {
        const driver = await issue.loadDriver();
        if (driver) {
            this.driverActions.panel.view(driver);
        }
    }

    @action async viewVehicle(issue) {
        const vehicle = await issue.loadVehicle();
        if (vehicle) {
            this.vehicleActions.panel.view(vehicle);
        }
    }
}
