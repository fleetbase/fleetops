import ResourceActionService from '@fleetbase/ember-core/services/resource-action';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';

export default class WorkOrderActionsService extends ResourceActionService {
    @service fetch;
    @service notifications;
    constructor() {
        super(...arguments);
        this.initialize('work-order');
    }

    transition = {
        view: (workOrder) => this.transitionTo('maintenance.work-orders.index.details', workOrder),
        edit: (workOrder) => this.transitionTo('maintenance.work-orders.index.edit', workOrder),
        create: () => this.transitionTo('maintenance.work-orders.index.new'),
    };

    panel = {
        create: (attributes = {}) => {
            const workOrder = this.createNewInstance(attributes);
            return this.resourceContextPanel.open({
                content: 'work-order/form',
                title: this.intl.t('common.create-a-new-resource', { resource: this.intl.t('resource.work order')?.toLowerCase() }),
                useDefaultSaveTask: true,
                saveOptions: {
                    callback: this.refresh,
                },
                workOrder,
            });
        },
        edit: (workOrder) => {
            return this.resourceContextPanel.open({
                content: 'work-order/form',
                title: this.intl.t('common.edit-resource-name', { resourceName: workOrder.name }),
                useDefaultSaveTask: true,
                workOrder,
            });
        },
        view: (workOrder) => {
            return this.resourceContextPanel.open({
                workOrder,
                tabs: [
                    {
                        label: this.intl.t('common.overview'),
                        component: 'work-order/details',
                    },
                ],
            });
        },
    };

    /**
     * Packs completion data into workOrder.meta.completion_data before saving.
     *
     * Monetary values (laborCost, partsCost, tax) come from the MoneyInput
     * component's @onChange callback, which already emits **cents** (integers).
     * No further conversion is needed — values are stored as-is.
     *
     * Called by the edit/new controllers before workOrder.save().
     *
     * @param {WorkOrderModel} workOrder
     * @param {Object} completionData  — plain object from the form component's @onCompletionChange
     */
    prepareForSave(workOrder, completionData = {}) {
        if (workOrder.status !== 'closed' || !completionData) {
            return;
        }

        // Ensure values are integers (MoneyInput emits cents already)
        const toIntCents = (value) => {
            if (value === null || value === undefined) return null;
            const parsed = parseInt(value, 10);
            return isNaN(parsed) ? null : parsed;
        };

        const laborCostCents = toIntCents(completionData.laborCost);
        const partsCostCents = toIntCents(completionData.partsCost);
        const taxCents = toIntCents(completionData.tax);
        const totalCostCents = laborCostCents !== null || partsCostCents !== null || taxCents !== null ? (laborCostCents ?? 0) + (partsCostCents ?? 0) + (taxCents ?? 0) : null;

        workOrder.meta = {
            ...(workOrder.meta ?? {}),
            completion_data: {
                odometer: completionData.odometer ? parseFloat(completionData.odometer) : null,
                engine_hours: completionData.engineHours ? parseFloat(completionData.engineHours) : null,
                labor_cost: laborCostCents,
                parts_cost: partsCostCents,
                tax: taxCents,
                total_cost: totalCostCents,
                currency: workOrder.currency ?? 'USD',
                notes: completionData.notes ?? null,
            },
        };
    }

    @action sendEmail(workOrder, options = {}) {
        const assignee = workOrder.assignee;
        const vendorName = assignee?.name ?? workOrder.assignee_name ?? null;
        const vendorEmail = assignee?.email ?? null;
        const vendorPhone = assignee?.phone ?? null;

        this.modalsManager.show('modals/send-work-order', {
            title: 'Send Work Order to Vendor',
            acceptButtonText: 'Send Work Order',
            acceptButtonIcon: 'paper-plane',
            acceptButtonDisabled: !vendorEmail,
            declineButtonText: 'Cancel',
            workOrder,
            vendorName,
            vendorEmail,
            vendorPhone,
            confirm: async (modal) => {
                modal.startLoading();
                try {
                    const response = await this.fetch.post(`work-orders/${workOrder.id}/send`);
                    this.notifications.success(response?.message ?? `Work order sent to ${vendorName ?? 'vendor'} successfully.`);
                    modal.done();
                } catch (error) {
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
            ...options,
        });
    }

    modal = {
        create: (attributes = {}, options = {}, saveOptions = {}) => {
            const workOrder = this.createNewInstance(attributes);
            return this.modalsManager.show('modals/resource', {
                resource: workOrder,
                title: this.intl.t('common.create-a-new-resource', { resource: this.intl.t('resource.work order')?.toLowerCase() }),
                acceptButtonText: this.intl.t('common.create-resource', { resource: this.intl.t('resource.work-order') }),
                component: 'work-order/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', workOrder, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        edit: (workOrder, options = {}, saveOptions = {}) => {
            return this.modalsManager.show('modals/resource', {
                resource: workOrder,
                title: this.intl.t('common.edit-resource-name', { resourceName: workOrder.name }),
                acceptButtonText: this.intl.t('common.save-changes'),
                saveButtonIcon: 'save',
                component: 'work-order/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', workOrder, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        view: (workOrder, options = {}) => {
            return this.modalsManager.show('modals/resource', {
                resource: workOrder,
                title: workOrder.name,
                component: 'work-order/details',
                ...options,
            });
        },
    };
}
