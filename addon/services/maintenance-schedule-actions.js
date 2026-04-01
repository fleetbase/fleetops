import ResourceActionService from '@fleetbase/ember-core/services/resource-action';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';

export default class MaintenanceScheduleActionsService extends ResourceActionService {
    @service fetch;
    @service notifications;
    @service intl;

    constructor() {
        super(...arguments);
        this.initialize('maintenance-schedule');
    }

    transition = {
        view: (schedule) => this.transitionTo('maintenance.schedules.index.details', schedule),
        edit: (schedule) => this.transitionTo('maintenance.schedules.index.edit', schedule),
        create: () => this.transitionTo('maintenance.schedules.index.new'),
    };

    panel = {
        create: (attributes = {}) => {
            const schedule = this.createNewInstance(attributes);
            return this.resourceContextPanel.open({
                content: 'maintenance-schedule/form',
                title: this.intl.t('common.create-a-new-resource', { resource: this.intl.t('resource.maintenance-schedule')?.toLowerCase() }),
                useDefaultSaveTask: true,
                saveOptions: {
                    callback: this.refresh,
                },
                schedule,
            });
        },
        edit: (schedule) => {
            return this.resourceContextPanel.open({
                content: 'maintenance-schedule/form',
                title: this.intl.t('common.edit-resource-name', { resourceName: schedule.name }),
                useDefaultSaveTask: true,
                schedule,
            });
        },
        view: (schedule) => {
            return this.resourceContextPanel.open({
                schedule,
                tabs: [
                    {
                        label: this.intl.t('common.overview'),
                        component: 'maintenance-schedule/details',
                    },
                ],
            });
        },
    };

    modal = {
        create: (attributes = {}, options = {}, saveOptions = {}) => {
            const schedule = this.createNewInstance(attributes);
            return this.modalsManager.show('modals/resource', {
                resource: schedule,
                title: this.intl.t('common.create-a-new-resource', { resource: this.intl.t('resource.maintenance-schedule')?.toLowerCase() }),
                acceptButtonText: this.intl.t('common.create-resource', { resource: this.intl.t('resource.maintenance-schedule') }),
                component: 'maintenance-schedule/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', schedule, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        edit: (schedule, options = {}, saveOptions = {}) => {
            return this.modalsManager.show('modals/resource', {
                resource: schedule,
                title: this.intl.t('common.edit-resource-name', { resourceName: schedule.name }),
                acceptButtonText: this.intl.t('common.save-changes'),
                saveButtonIcon: 'save',
                component: 'maintenance-schedule/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', schedule, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
    };

    /**
     * Pause a maintenance schedule.
     */
    @action async pause(schedule) {
        try {
            await this.fetch.post(`maintenance-schedules/${schedule.id}/pause`);
            schedule.set('status', 'paused');
            this.notifications.success('Schedule paused successfully.');
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    /**
     * Resume a paused maintenance schedule.
     */
    @action async resume(schedule) {
        try {
            await this.fetch.post(`maintenance-schedules/${schedule.id}/resume`);
            schedule.set('status', 'active');
            this.notifications.success('Schedule resumed successfully.');
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    /**
     * Manually trigger a work order from a schedule immediately.
     */
    @action async triggerNow(schedule) {
        try {
            const response = await this.fetch.post(`maintenance-schedules/${schedule.id}/trigger`);
            this.notifications.success(`Work order ${response?.work_order?.public_id ?? ''} created from schedule.`);
            this.refresh();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
}
