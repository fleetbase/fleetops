import ResourceActionService, { service } from '@fleetbase/ember-core/services/resource-action';
import { action } from '@ember/object';
import { serializeRecurringDraftOrder } from '../utils/recurring-order-blueprint';
import { buildRrule } from '../utils/recurring-rrule';

export default class RecurringOrderScheduleActionsService extends ResourceActionService {
    @service store;
    @service fetch;
    @service notifications;
    @service intl;
    @service events;

    constructor() {
        super(...arguments);
        this.initialize('recurring-order-schedule');
    }

    createNewInstance(attributes = {}) {
        return this.store.createRecord('recurring-order-schedule', {
            status: 'active',
            timezone: Intl.DateTimeFormat().resolvedOptions().timeZone ?? 'UTC',
            starts_at: new Date(),
            rrule: buildRrule({
                frequency: 'weekly',
                interval: 1,
                weekdays: ['MO'],
                monthday: new Date().getDate(),
                until: null,
            }),
            ...attributes,
        });
    }

    modal = {
        create: (attributes = {}, options = {}, saveOptions = {}) => {
            const schedule = this.createNewInstance(attributes);
            return this.openFormModal(schedule, options, saveOptions);
        },
        createFromOrder: (order, options = {}, saveOptions = {}) => {
            return this.modal.create(
                {},
                {
                    ...options,
                    sourceOrder: order,
                },
                saveOptions
            );
        },
        edit: (schedule, options = {}, saveOptions = {}) => {
            return this.openFormModal(schedule, options, saveOptions);
        },
        view: (schedule, options = {}) => {
            return this.modalsManager.show('modals/resource', {
                resource: schedule,
                component: 'recurring-order-schedule/details',
                title: schedule.name ?? schedule.public_id ?? this.intl.t('resource.recurring-order-schedule'),
                modalClass: 'modal-xl',
                hideAcceptButton: true,
                declineButtonText: this.intl.t('common.done'),
                ...options,
            });
        },
        manage: (options = {}) => {
            return this.modalsManager.show('modals/recurring-order-schedules-manager', {
                title: this.intl.t('resource.recurring-order-schedules'),
                modalClass: 'modal-xl flb-resource-modal',
                hideFooterActions: true,
                ...options,
            });
        },
    };

    transition = {
        create: () => {
            return this.hostRouter.transitionTo('console.fleet-ops.operations.orders.index.new', {
                queryParams: {
                    repeat: true,
                    source_order: null,
                    source_series: null,
                },
            });
        },
        createFromOrder: (order) => {
            return this.hostRouter.transitionTo('console.fleet-ops.operations.orders.index.new', {
                queryParams: {
                    repeat: true,
                    source_order: order?.public_id ?? order?.id ?? null,
                    source_series: null,
                },
            });
        },
        view: (series) => {
            return this.transitionTo('operations.orders.index.series.details', series?.public_id ?? series?.id ?? series);
        },
        editTemplate: (series) => {
            return this.hostRouter.transitionTo('console.fleet-ops.operations.orders.index.new', {
                queryParams: {
                    repeat: true,
                    source_order: null,
                    source_series: series?.public_id ?? series?.id ?? series,
                },
            });
        },
        manage: () => {
            return this.transitionTo('operations.orders.index.series');
        },
    };

    buildPayload(schedule, overrides = {}) {
        return {
            recurring_order_schedule: {
                name: schedule.name,
                description: schedule.description,
                status: schedule.status ?? 'active',
                timezone: schedule.timezone ?? 'UTC',
                starts_at: schedule.starts_at,
                ends_at: schedule.ends_at,
                rrule: overrides.rrule ?? schedule.rrule,
                meta: schedule.meta ?? {},
                service_rate_uuid: schedule.service_rate_uuid ?? null,
                order: serializeRecurringDraftOrder(schedule.draftOrder, schedule.service_rate_uuid ?? schedule.service_rate?.id ?? null),
            },
        };
    }

    async save(schedule, overrides = {}) {
        const payload = this.buildPayload(schedule, overrides);
        const method = schedule.isNew ? 'post' : 'patch';
        const path = schedule.isNew ? 'recurring-order-schedules' : `recurring-order-schedules/${schedule.id ?? schedule.public_id}`;

        return this.fetch[method](path, payload, {
            normalizeToEmberData: true,
            normalizeModelType: 'recurring-order-schedule',
        });
    }

    async preview(schedule, limit = 8, overrides = {}) {
        const payload = {
            ...this.buildPayload(schedule, overrides),
            limit,
        };

        return this.fetch.post('recurring-order-schedules/preview', payload);
    }

    openFormModal(schedule, options = {}, saveOptions = {}) {
        const isNew = schedule.isNew;
        const title = options.title ?? (isNew ? 'Create recurring schedule' : `Edit ${schedule.name ?? schedule.public_id ?? 'recurring schedule'}`);
        const acceptButtonText = options.acceptButtonText ?? (isNew ? 'Create recurring schedule' : this.intl.t('common.save-changes'));

        return this.modalsManager.show('modals/recurring-order-schedule-form', {
            resource: schedule,
            sourceOrder: options.sourceOrder,
            title,
            modalClass: options.modalClass,
            modalBodyClass: options.modalBodyClass ?? 'overflow-y-scroll',
            acceptButtonText,
            acceptButtonIcon: options.acceptButtonIcon ?? (isNew ? 'plus' : 'save'),
            confirm: (modal) => this.confirmFormModal(modal, schedule, saveOptions),
            ...options,
        });
    }

    async confirmFormModal(modal, schedule, saveOptions = {}) {
        modal.startLoading();

        try {
            const wasNew = schedule.isNew;
            const persisted = await this.save(schedule);

            if (wasNew) {
                this.events.trackResourceCreated?.(persisted);
            }

            if (saveOptions.refresh !== false) {
                await this.hostRouter.refresh();
            }

            if (typeof saveOptions.onSave === 'function') {
                await saveOptions.onSave(persisted);
            }

            const message = wasNew
                ? this.intl.t('common.resource-created-success', { resource: this.intl.t('resource.recurring-order-schedule') })
                : this.intl.t('common.resource-updated-success', { resource: this.intl.t('resource.recurring-order-schedule') });

            this.notifications.success(message);
            modal.done();
            return persisted;
        } catch (error) {
            this.notifications.serverError(error);
            modal.stopLoading();
            throw error;
        }
    }

    @action async pause(schedule) {
        try {
            await this.fetch.post(`recurring-order-schedules/${schedule.id ?? schedule.public_id}/pause`);
            schedule.status = 'paused';
            this.notifications.success('Recurring order schedule paused.');
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action async resume(schedule) {
        try {
            await this.fetch.post(`recurring-order-schedules/${schedule.id ?? schedule.public_id}/resume`);
            schedule.status = 'active';
            this.notifications.success('Recurring order schedule resumed.');
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action async cancelFuture(schedule, options = {}) {
        try {
            await this.fetch.post(`recurring-order-schedules/${schedule.id ?? schedule.public_id}/cancel-future`, {
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
            await this.fetch.post(`recurring-order-schedules/${schedule.id ?? schedule.public_id}/skip-occurrence`, {
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

    @action async skipNextOccurrence(schedule) {
        const occurrenceAt = schedule?.next_occurrence_at ?? schedule?.upcoming_occurrences?.[0]?.occurrence_at;

        if (!occurrenceAt) {
            return this.notifications.warning('No upcoming occurrence is available to skip.');
        }

        return this.skipOccurrence(schedule, occurrenceAt);
    }
}
