import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { isArray } from '@ember/array';

export default class MaintenanceSchedulesIndexDetailsController extends Controller {
    @service maintenanceScheduleActions;
    @service fetch;
    @service hostRouter;
    @service intl;
    @service abilities;
    @service('universe/menu-service') menuService;
    @tracked overlay;

    get tabs() {
        const registeredTabs = this.menuService.getMenuItems('fleet-ops:component:schedule:details');
        return [
            { route: 'maintenance.schedules.index.details.index', label: this.intl.t('common.overview') },
            { route: 'maintenance.schedules.index.details.work-orders', label: this.intl.t('menu.work-orders') },
            ...(isArray(registeredTabs) ? registeredTabs : []),
        ];
    }

    get actionButtons() {
        return [
            { icon: 'edit', fn: this.edit, permission: 'fleet-ops update maintenance-schedule' },
            { icon: 'play', helpText: 'Trigger Work Order Now', fn: this.triggerNow, permission: 'fleet-ops update maintenance-schedule' },
            {
                // Calendar export dropdown — matches the dropdown-button pattern
                icon: 'ellipsis-h',
                iconPrefix: 'fas',
                renderInPlace: true,
                items: [
                    {
                        text: 'Download .ics',
                        icon: 'download',
                        iconPrefix: 'far',
                        fn: () => this.downloadIcal(this.model),
                    },
                    {
                        text: 'Add to Google Calendar',
                        icon: 'calendar-plus',
                        iconPrefix: 'fab',
                        fn: () => this.addToGoogleCalendar(this.model),
                    },
                ],
            },
            { icon: 'trash', fn: this.delete, permission: 'fleet-ops delete maintenance-schedule', type: 'danger' },
        ];
    }

    @action edit() {
        return this.hostRouter.transitionTo('console.fleet-ops.maintenance.schedules.index.edit', this.model);
    }

    @action triggerNow() {
        return this.maintenanceScheduleActions.triggerNow(this.model);
    }

    @action delete() {
        return this.maintenanceScheduleActions.delete(this.model, {
            onConfirm: () => {
                this.hostRouter.transitionTo('console.fleet-ops.maintenance.schedules.index');
            },
        });
    }

    /**
     * Trigger a browser download of the .ics file for this schedule.
     * Uses fetch.download() which automatically attaches auth headers.
     */
    @action downloadIcal(schedule) {
        const id = schedule.public_id ?? schedule.id;
        this.fetch
            .download(
                `maintenance-schedules/${id}/ical`,
                {},
                {
                    fileName: `maintenance-schedule-${id}.ics`,
                    mimeType: 'text/calendar',
                }
            )
            .catch((error) => {
                // eslint-disable-next-line no-console
                console.error('Failed to download iCal:', error);
            });
    }

    /**
     * Open the Google Calendar "add event" URL for this schedule.
     * Includes RRULE recurrence when the schedule has a time-based interval.
     */
    @action addToGoogleCalendar(schedule) {
        const title = encodeURIComponent(schedule.name ?? 'Maintenance Schedule');
        const dueDate = schedule.next_due_date ? new Date(schedule.next_due_date) : new Date();
        const pad = (n) => String(n).padStart(2, '0');
        const dateStr = `${dueDate.getFullYear()}${pad(dueDate.getMonth() + 1)}${pad(dueDate.getDate())}`;
        const details = encodeURIComponent(schedule.description ?? schedule.instructions ?? '');

        // Build RRULE if the schedule has a time-based interval
        let recur = '';
        const intervalValue = parseInt(schedule.interval_value, 10);
        const intervalUnit = schedule.interval_unit;
        if (intervalValue > 0 && intervalUnit) {
            const unitMap = { days: 'DAILY', weeks: 'WEEKLY', months: 'MONTHLY', years: 'YEARLY' };
            const freq = unitMap[intervalUnit] ?? 'DAILY';
            const interval = intervalUnit === 'weeks' ? intervalValue : intervalValue;
            recur = `&recur=RRULE:FREQ=${freq};INTERVAL=${interval}`;
        }

        const url = `https://calendar.google.com/calendar/render?action=TEMPLATE&text=${title}&dates=${dateStr}/${dateStr}&details=${details}${recur}`;
        window.open(url, '_blank', 'noopener,noreferrer');
    }
}
