import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { debug } from '@ember/debug';
import { task } from 'ember-concurrency';

export default class OrderFormDetailsComponent extends Component {
    @service store;
    @service orderCreation;
    @service orderConfigActions;
    @service customFieldsRegistry;
    @service leafletMapManager;
    @service leafletLayerVisibilityManager;
    @service currentUser;
    @tracked customFields;

    constructor() {
        super(...arguments);
        this.orderConfigActions.loadAll.perform();
    }

    @action selectFacilitator(model) {
        this.args.resource.set('facilitator', model);
        this.args.resource.set('driver', null);
    }

    @task *selectOrderConfig(orderConfig) {
        if (!orderConfig) return;
        this.args.resource.setProperties({
            order_config_uuid: orderConfig.id,
            order_config: orderConfig,
            type: orderConfig.key,
        });
        this.args.resource.payload.set('type', orderConfig.key);

        try {
            const customFieldsManager = yield this.customFieldsRegistry.loadSubjectCustomFields.perform(orderConfig);
            this.orderCreation.addContext('cfManager', customFieldsManager);
            this.customFields = customFieldsManager;
            this.args.resource.cfManager = customFieldsManager;
            if (typeof this.args.onCustomFieldsReady === 'function') {
                this.args.onCustomFieldsReady(customFieldsManager);
            }
        } catch (err) {
            debug('Error loading order custom fields: ' + err.message);
        }
    }

    @task *selectDriver(driver) {
        this.args.resource.set('driver_assigned', driver);

        try {
            const vehicle = yield driver.vehicle;
            if (vehicle) {
                this.args.resource.set('vehicle_assigned', vehicle);
            }
        } catch (err) {
            debug('Unable to load and set driver vehicle: ' + err.message);
        }

        // Show & track driver assigned
        this.leafletLayerVisibilityManager.hideCategory('drivers');
        this.leafletLayerVisibilityManager.showModelLayer(this.args.resource.driver_assigned);
    }

    /**
     * Resolves the reference date to use when pre-populating the date portion
     * of a time window field. Prefers scheduled_at, falls back to created_at,
     * and finally falls back to now so there is always a valid date.
     *
     * @returns {Date}
     */
    get _timeWindowReferenceDate() {
        const raw = this.args.resource.scheduled_at ?? this.args.resource.created_at ?? new Date();
        return raw instanceof Date ? raw : new Date(raw);
    }

    /**
     * Called by DateTimeInput @onUpdate for time_window_start and time_window_end.
     *
     * When the user picks a time, we preserve their chosen time but replace the
     * date portion with the order's scheduled_at date (or created_at if
     * scheduled_at is not set). This means the user only ever needs to set the
     * time — the date is always contextually correct.
     *
     * If the incoming value already carries a different date (e.g. the user
     * explicitly changed it via the date part of the picker) we respect that
     * and do not override it.
     *
     * @param {'time_window_start'|'time_window_end'} field
     * @param {Date|string|null} value  Value emitted by DateTimeInput
     */
    @action setTimeWindow(field, value) {
        if (!value) {
            this.args.resource[field] = null;
            return;
        }

        const picked = value instanceof Date ? value : new Date(value);
        if (isNaN(picked.getTime())) {
            this.args.resource[field] = value;
            return;
        }

        const ref = this._timeWindowReferenceDate;

        // Only inject the reference date when the picked value has no meaningful
        // date of its own — i.e. when the date portion is the Unix epoch
        // (1970-01-01), which is what DateTimeInput emits when the user has only
        // touched the time picker and not the date picker.
        const isEpochDate = picked.getUTCFullYear() === 1970 && picked.getUTCMonth() === 0 && picked.getUTCDate() === 1;

        if (isEpochDate) {
            const merged = new Date(ref);
            merged.setHours(picked.getHours(), picked.getMinutes(), picked.getSeconds(), 0);
            this.args.resource[field] = merged;
        } else {
            // User explicitly set a date — honour it as-is.
            this.args.resource[field] = picked;
        }
    }

    @action toggleAdhoc(toggled) {
        this.args.resource.adhoc = toggled;
        this.args.resource.adhoc_distance = this.currentUser.getCompanyOption('fleetops.adhoc_distance', 5000);
    }

    @action toggleProofOfDelivery(toggled) {
        this.args.resource.pod_required = toggled;
        this.args.resource.pod_method = toggled ? 'scan' : null;
    }
}
