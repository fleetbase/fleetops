import ResourceActionService, { service } from '@fleetbase/ember-core/services/resource-action';
import { action } from '@ember/object';
import { serializeRecurringDraftOrder } from '../utils/recurring-order-blueprint';

export default class RecurringOrderScheduleActionsService extends ResourceActionService {
    @service store;
    @service fetch;
    @service notifications;
    @service intl;

    constructor() {
        super(...arguments);
        this.initialize('recurring-order-schedule');
    }

    createNewInstance(attributes = {}) {
        return this.store.createRecord('recurring-order-schedule', {
            status: 'active',
            timezone: Intl.DateTimeFormat().resolvedOptions().timeZone ?? 'UTC',
            starts_at: new Date(),
            ...attributes,
        });
    }

    transition = {
        view: (schedule) => this.transitionTo('operations.recurring-orders.index.details', schedule),
        edit: (schedule) => this.transitionTo('operations.recurring-orders.index.edit', schedule),
        create: () => this.transitionTo('operations.recurring-orders.index.new'),
    };

    buildPayload(schedule) {
        return {
            recurring_order_schedule: {
                name: schedule.name,
                description: schedule.description,
                status: schedule.status ?? 'active',
                timezone: schedule.timezone ?? 'UTC',
                starts_at: schedule.starts_at,
                ends_at: schedule.ends_at,
                rrule: schedule.rrule,
                meta: schedule.meta ?? {},
                service_rate_uuid: schedule.service_rate_uuid ?? null,
                order: serializeRecurringDraftOrder(schedule.draftOrder, schedule.service_rate_uuid ?? schedule.service_rate?.id ?? null),
            },
        };
    }

    async save(schedule) {
        const payload = this.buildPayload(schedule);
        const method = schedule.isNew ? 'post' : 'patch';
        const path = schedule.isNew ? 'recurring-order-schedules' : `recurring-order-schedules/${schedule.id ?? schedule.public_id}`;

        return this.fetch[method](path, payload, {
            normalizeToEmberData: true,
            normalizeModelType: 'recurring-order-schedule',
        });
    }

    async preview(schedule, limit = 8) {
        const payload = {
            ...this.buildPayload(schedule),
            limit,
        };

        return this.fetch.post('recurring-order-schedules/preview', payload);
    }

    @action async pause(schedule) {
        try {
            await this.fetch.post(`recurring-order-schedules/${schedule.id}/pause`);
            schedule.status = 'paused';
            this.notifications.success('Recurring order schedule paused.');
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action async resume(schedule) {
        try {
            await this.fetch.post(`recurring-order-schedules/${schedule.id}/resume`);
            schedule.status = 'active';
            this.notifications.success('Recurring order schedule resumed.');
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action async cancelFuture(schedule, options = {}) {
        try {
            await this.fetch.post(`recurring-order-schedules/${schedule.id}/cancel-future`, {
                cancel_generated_orders: Boolean(options.cancelGeneratedOrders),
            });
            schedule.status = 'canceled';
            this.notifications.success('Recurring order schedule canceled.');
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action async skipOccurrence(schedule, occurrenceAt, reason = null) {
        try {
            await this.fetch.post(`recurring-order-schedules/${schedule.id}/skip-occurrence`, {
                occurrence_at: occurrenceAt,
                reason,
                cancel_generated_order: true,
            });
            this.notifications.success('Upcoming recurring order canceled.');
            await this.hostRouter.refresh();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
}
