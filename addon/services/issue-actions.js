import ResourceActionService from '@fleetbase/ember-core/services/resource-action';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { format } from 'date-fns';
import { issueStatuses } from '../utils/fleet-ops-options';
import { PANEL_DEFAULTS } from '../utils/context-panel';

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
                title: this.intl.t('common.create-a-new-resource', { resource: this.intl.t('resource.issue')?.toLowerCase() }),
                useDefaultSaveTask: true,
                saveOptions: {
                    callback: this.refresh,
                },
                issue,
            });
        },
        edit: (issue) => {
            return this.resourceContextPanel.open({
                content: 'issue/form',
                title: this.intl.t('common.edit-resource-name', { resourceName: issue.name }),
                useDefaultSaveTask: true,
                issue,
            });
        },
        view: (issue, options = {}) => {
            return this.resourceContextPanel.open({
                issue,
                header: 'issue/panel-header',
                width: '800px',
                actionButtons: this.panelActionButtons(issue),
                tabs: [{ key: 'overview', label: this.intl.t('common.overview'), component: 'issue/details' }],
                ...PANEL_DEFAULTS,
                ...options,
            });
        },
    };

    modal = {
        create: (attributes = {}, options = {}, saveOptions = {}) => {
            const issue = this.createNewInstance(attributes);
            return this.modalsManager.show('modals/resource', {
                resource: issue,
                title: this.intl.t('common.create-a-new-resource', { resource: this.intl.t('resource.issue')?.toLowerCase() }),
                acceptButtonText: this.intl.t('common.create-resource', { resource: this.intl.t('resource.issue') }),
                component: 'issue/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', issue, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        edit: (issue, options = {}, saveOptions = {}) => {
            return this.modalsManager.show('modals/resource', {
                resource: issue,
                title: this.intl.t('common.edit-resource-name', { resourceName: issue.name }),
                acceptButtonText: this.intl.t('common.save-changes'),
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

    /**
     * The header buttons of an issue panel, the same as the issue details
     * route: edit, then the workflow menu. The menu is read when it renders,
     * so closing or re-opening the issue swaps the last item.
     */
    panelActionButtons(issue) {
        const service = this;
        const refresh = () => issue?.reload?.();

        return [
            {
                icon: 'pencil',
                permission: 'fleet-ops update issue',
                fn: async () => {
                    await this.resourceContextPanel.closeAll();
                    this.panel.edit(issue);
                },
            },
            {
                icon: 'ellipsis',
                type: 'default',
                renderInPlace: true,
                get items() {
                    return service.workflowItems(issue, refresh);
                },
            },
        ];
    }

    workflowItems(issue, onSaved) {
        const items = [
            { label: 'Change Status', text: 'Change Status', icon: 'arrows-rotate', fn: () => this.openStatusModal(issue, { onSaved }) },
            { label: 'Assign Issue', text: 'Assign Issue', icon: 'user-check', fn: () => this.openAssignModal(issue, { onSaved }) },
        ];

        if (this.closedStatuses.includes(issue?.status)) {
            items.push({ label: 'Re-open Issue', text: 'Re-open Issue', icon: 'rotate-left', fn: () => this.confirmReopenIssue(issue, { onSaved }) });
        } else {
            items.push({
                label: 'Close Issue',
                text: 'Close Issue',
                icon: 'circle-check',
                class: 'text-green-600 dark:text-green-400',
                fn: () => this.openCloseIssueModal(issue, { onSaved }),
            });
        }

        return items;
    }

    get closedStatuses() {
        return ['closed', 'resolved', 'completed'];
    }

    openStatusModal(issue, options = {}) {
        return this.modalsManager.show('modals/issue-status', {
            issue,
            statusOptions: issueStatuses,
            onSaved: options.onSaved,
        });
    }

    openAssignModal(issue, options = {}) {
        return this.modalsManager.show('modals/issue-assign', {
            issue,
            onSaved: options.onSaved,
        });
    }

    openCloseIssueModal(issue, options = {}) {
        return this.modalsManager.show('modals/issue-close', {
            issue,
            onSaved: options.onSaved,
        });
    }

    confirmReopenIssue(issue, options = {}) {
        this.modalsManager.confirm({
            title: `Re-open ${issue.public_id || 'issue'}?`,
            body: 'This will move the issue back into an active re-opened state and clear the active resolution date.',
            acceptButtonText: 'Re-open Issue',
            acceptButtonIcon: 'rotate-left',
            confirm: async (modal) => {
                modal.startLoading();

                try {
                    const meta = issue.meta ?? {};
                    const reopenHistory = Array.isArray(meta.reopen_history) ? meta.reopen_history : [];
                    const user = this.currentUser.user;

                    issue.status = 're_opened';
                    issue.resolved_at = null;
                    issue.meta = {
                        ...meta,
                        reopen_history: [
                            ...reopenHistory,
                            {
                                reopened_at: new Date().toISOString(),
                                reopened_by_uuid: user?.id,
                                reopened_by_name: user?.name,
                            },
                        ],
                    };

                    await issue.save();
                    this.notifications.success('Issue re-opened.');

                    if (typeof options.onSaved === 'function') {
                        await options.onSaved(issue);
                    }

                    modal.done();
                } catch (error) {
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
        });
    }

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
