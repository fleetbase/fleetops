import Controller, { inject as controller } from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import { debug } from '@ember/debug';
import isNotEmpty from '@fleetbase/ember-core/utils/is-not-empty';
import { createRecurringDraftOrder } from '../../../../utils/recurring-order-blueprint';

export default class OperationsOrdersIndexNewController extends Controller {
    @controller('operations.orders.index') index;
    @service loader;
    @service intl;
    @service universe;
    @service notifications;
    @service hostRouter;
    @service placeActions;
    @service customerActions;
    @service vendorActions;
    @service entityActions;
    @service orderImport;
    @service orderCreation;
    @service orderValidation;
    @service recurringOrderScheduleActions;
    @service store;
    @service events;
    @service sidebar;
    @tracked order = this.orderCreation.newOrder();
    @tracked overlay;
    @tracked repeat = false;
    @tracked source_order = null;
    @tracked source_series = null;
    @tracked repeatEnabled = false;
    @tracked scheduleFirst = false;
    @tracked seriesDraft = null;
    @tracked editingSeries = false;

    queryParams = ['repeat', 'source_order', 'source_series'];

    /** action buttons */
    get actionButtons() {
        return [
            {
                text: this.intl.t('common.import'),
                icon: 'upload',
                type: 'magic',
                onClick: () => this.orderImport.promptImport(this.order),
            },
            {
                icon: 'ellipsis-h',
                triggerClass: 'hidden md:flex',
                items: [
                    {
                        icon: 'user-plus',
                        text: this.intl.t('order.actions.create-new-customer'),
                        fn: this.customerActions.modal.create,
                    },
                    {
                        icon: 'truck',
                        text: this.intl.t('order.actions.create-new-facilitator'),
                        fn: this.vendorActions.modal.create,
                    },
                    {
                        icon: 'map-pin',
                        text: this.intl.t('order.actions.create-new-place'),
                        fn: this.placeActions.modal.create,
                    },
                ],
            },
        ];
    }

    get headerTitle() {
        if (this.editingSeries) {
            return 'Edit recurring series';
        }

        return this.repeatEnabled ? 'Create a recurring series' : this.intl.t('common.create-a-new-resource', { resource: this.intl.t('resource.order').toLowerCase() });
    }

    get saveDisabled() {
        if (this.repeatEnabled) {
            return this.recurringSeriesValidationFails(this.order);
        }

        return this.orderValidation.validationFails(this.order);
    }

    recurringSeriesValidationFails(order = this.order) {
        const hasValidOrderTemplate = !this.orderValidation.validationFails(order);
        const hasSeriesName = isNotEmpty(this.seriesDraft?.name);
        const hasSeriesStart = isNotEmpty(order?.scheduled_at) || isNotEmpty(this.seriesDraft?.starts_at);
        const hasRecurrenceRule = isNotEmpty(this.seriesDraft?.rrule);

        return !(hasValidOrderTemplate && hasSeriesName && hasSeriesStart && hasRecurrenceRule);
    }

    @task *save(order) {
        if (this.repeatEnabled) {
            this.ensureSeriesDraft(order);

            if (this.recurringSeriesValidationFails(order)) {
                this.notifications.warning('Recurring series is missing required order or schedule details.');
                return;
            }

            return yield this.saveRecurringSeries(order);
        }

        if (this.orderValidation.validationFails(order)) return;

        // Display loader
        this.loader.showLoader('body', { loadingMessage: this.intl.t('common.creating-resource', { resource: this.intl.t('resource.order') }) + '...' });

        try {
            // Trigger creating event
            this.universe.trigger('fleet-ops.order.creating', order);

            // Save custom field values
            if (order.cfManager) {
                const { created: customFieldValues } = yield order.cfManager.saveTo(order);
                order.custom_field_values.pushObjects(customFieldValues);
            }

            // Save order
            const createdOrder = yield order.save();
            this.events.trackResourceCreated(order);

            // Trigger created event
            this.universe.trigger('fleet-ops.order.created', createdOrder);

            // Order creation completed
            yield this.hostRouter.transitionTo('console.fleet-ops.operations.orders.index.details', createdOrder);
            this.notifications.success(this.intl.t('common.resource-created-success-name', { resource: this.intl.t('resource.order'), resourceName: createdOrder.tracking }));
        } catch (err) {
            console.error(err);
            debug('Error creating order: ' + err.message);
            this.notifications.serverError(err);
        } finally {
            this.loader.removeLoader();
        }
    }

    async saveRecurringSeries(order) {
        this.ensureSeriesDraft(order);
        this.loader.showLoader('body', { loadingMessage: this.editingSeries ? 'Updating recurring series...' : 'Creating recurring series...' });

        try {
            this.seriesDraft.draftOrder = order;
            this.seriesDraft.starts_at = order.scheduled_at ?? this.seriesDraft.starts_at ?? new Date();

            const createdSeries = await this.recurringOrderScheduleActions.save(this.seriesDraft);
            if (this.editingSeries) {
                this.events.trackResourceUpdated?.(createdSeries);
            } else {
                this.events.trackResourceCreated?.(createdSeries);
            }

            await this.hostRouter.transitionTo('console.fleet-ops.operations.orders.index.series.details', createdSeries.public_id ?? createdSeries.id);
            this.notifications.success(this.editingSeries ? 'Recurring series updated.' : 'Recurring series created.');
        } catch (err) {
            console.error(err);
            debug('Error creating recurring series: ' + err.message);
            this.notifications.serverError(err);
        } finally {
            this.loader.removeLoader();
        }
    }

    @action setup() {
        this.index.changeLayout('map');
    }

    @action onRepeatChange(enabled) {
        this.repeatEnabled = Boolean(enabled);

        if (this.repeatEnabled) {
            this.ensureSeriesDraft(this.order);
        }
    }

    configureRepeatMode({ repeat = false, sourceOrder = null, sourceOrderId = null, sourceSeries = null, sourceSeriesId = null } = {}) {
        this.source_order = sourceOrderId ?? null;
        this.source_series = sourceSeriesId ?? null;
        this.editingSeries = Boolean(sourceSeries);

        if (sourceOrder || sourceSeries) {
            this.order = createRecurringDraftOrder(this.store, sourceOrder ?? sourceSeries);
            this.orderCreation.addContext('order', this.order);
        }

        this.repeatEnabled = Boolean(repeat);
        this.repeat = this.repeatEnabled;
        this.scheduleFirst = this.repeatEnabled;

        if (this.repeatEnabled) {
            this.ensureSeriesDraft(this.order, sourceOrder, sourceSeries);
        }
    }

    ensureSeriesDraft(order = this.order, sourceOrder = null, sourceSeries = null) {
        if (sourceSeries) {
            this.seriesDraft = sourceSeries;
        }

        if (!this.seriesDraft || this.seriesDraft.isDeleted || this.seriesDraft.isDestroying) {
            this.seriesDraft = this.recurringOrderScheduleActions.createNewInstance({
                name: sourceOrder ? `Recurring ${sourceOrder.tracking ?? sourceOrder.public_id ?? 'series'}` : null,
                starts_at: order?.scheduled_at ?? new Date(),
            });
        }

        this.seriesDraft.draftOrder = order;
        this.seriesDraft.starts_at = order?.scheduled_at ?? this.seriesDraft.starts_at ?? new Date();

        if (!this.seriesDraft.name && order?.customer?.name) {
            this.seriesDraft.name = `${order.customer.name} recurring series`;
        }

        return this.seriesDraft;
    }

    reset() {
        this.order = this.orderCreation.newOrder();
        this.orderCreation.addContext('order', this.order);
        this.seriesDraft = null;
        this.repeat = false;
        this.source_order = null;
        this.source_series = null;
        this.repeatEnabled = false;
        this.scheduleFirst = false;
        this.editingSeries = false;
    }
}
