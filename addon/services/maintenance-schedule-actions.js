import ResourceActionService from '@fleetbase/ember-core/services/resource-action';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { PANEL_DEFAULTS, closePanelsThen, registeredPanelTabs } from '../utils/context-panel';

export default class MaintenanceScheduleActionsService extends ResourceActionService {
    @service fetch;
    @service notifications;
    @service intl;
    @service('universe/menu-service') menuService;

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
        view: (schedule, options = {}) => {
            return this.resourceContextPanel.open({
                schedule,
                title: schedule?.name,
                actionButtons: this.panelActionButtons(schedule, { onDeleted: () => this.resourceContextPanel.closeAll() }),
                tabs: [
                    { key: 'overview', label: this.intl.t('common.overview'), component: 'maintenance-schedule/details' },
                    { key: 'work-orders', label: this.intl.t('menu.work-orders'), component: 'maintenance-schedule/work-orders' },
                    ...registeredPanelTabs(this.menuService, 'fleet-ops:component:schedule:details'),
                ],
                ...PANEL_DEFAULTS,
                ...options,
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

    /**
     * The header buttons of a schedule, shared by the details route and the
     * context panel: edit, trigger a work order now, calendar export, delete.
     * `onEdit` replaces the panel's edit step where the route navigates.
     */
    panelActionButtons(schedule, { onEdit, onDeleted } = {}) {
        return [
            {
                icon: 'edit',
                permission: 'fleet-ops update maintenance-schedule',
                fn: () => (onEdit ? onEdit(schedule) : closePanelsThen(this.resourceContextPanel, () => this.panel.edit(schedule))),
            },
            { icon: 'play', helpText: 'Trigger Work Order Now', permission: 'fleet-ops update maintenance-schedule', fn: () => this.triggerNow(schedule) },
            {
                icon: 'ellipsis-h',
                iconPrefix: 'fas',
                renderInPlace: true,
                items: [
                    { text: 'Download .ics', icon: 'download', iconPrefix: 'far', fn: () => this.downloadIcal(schedule) },
                    { text: 'Add to Google Calendar', icon: 'calendar-plus', iconPrefix: 'fab', fn: () => this.addToGoogleCalendar(schedule) },
                ],
            },
            { icon: 'trash', type: 'danger', permission: 'fleet-ops delete maintenance-schedule', fn: () => this.delete(schedule, { onConfirm: onDeleted }) },
        ];
    }

    /**
     * Download the schedule as an iCalendar file.
     */
    @action downloadIcal(schedule) {
        const id = schedule.public_id ?? schedule.id;

        return this.fetch.download(`maintenance-schedules/${id}/ical`, {}, { fileName: `maintenance-schedule-${id}.ics`, mimeType: 'text/calendar' }).catch((error) => {
            this.notifications.serverError(error);
        });
    }

    /**
     * Open Google Calendar with the schedule's next due date filled in,
     * repeating on the schedule's time interval when it has one.
     */
    @action addToGoogleCalendar(schedule) {
        const title = encodeURIComponent(schedule.name ?? 'Maintenance Schedule');
        const dueDate = schedule.next_due_date ? new Date(schedule.next_due_date) : new Date();
        const pad = (n) => String(n).padStart(2, '0');
        const dateStr = `${dueDate.getFullYear()}${pad(dueDate.getMonth() + 1)}${pad(dueDate.getDate())}`;
        const details = encodeURIComponent(schedule.description ?? schedule.instructions ?? '');

        let recur = '';
        const intervalValue = parseInt(schedule.interval_value, 10);
        const intervalUnit = schedule.interval_unit;
        if (intervalValue > 0 && intervalUnit) {
            const unitMap = { days: 'DAILY', weeks: 'WEEKLY', months: 'MONTHLY', years: 'YEARLY' };
            recur = `&recur=RRULE:FREQ=${unitMap[intervalUnit] ?? 'DAILY'};INTERVAL=${intervalValue}`;
        }

        const url = `https://calendar.google.com/calendar/render?action=TEMPLATE&text=${title}&dates=${dateStr}/${dateStr}&details=${details}${recur}`;
        window.open(url, '_blank', 'noopener,noreferrer');
    }
}
