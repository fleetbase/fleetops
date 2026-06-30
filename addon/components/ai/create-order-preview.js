import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action, get } from '@ember/object';
import { task, timeout } from 'ember-concurrency';

export default class AiCreateOrderPreviewComponent extends Component {
    @tracked draft = this.clone(this.args.preview?.draft ?? {});
    @tracked preview = this.args.preview;
    @tracked editingField = null;

    get payload() {
        return this.draft.payload ?? {};
    }

    get routeStops() {
        return this.preview?.route_preview?.stops ?? [];
    }

    get podMethods() {
        return this.preview?.options?.pod_methods ?? ['scan', 'signature', 'photo'];
    }

    get isReady() {
        return this.preview?.ready === true && !this.preview?.result && !this.isCancelled;
    }

    get isCancelled() {
        return this.args.task?.status === 'cancelled' || this.preview?.error?.type === 'cancelled';
    }

    get cancelledMessage() {
        return this.preview?.error?.message ?? 'This order creation preview was cancelled.';
    }

    get missingFields() {
        return this.preview?.missing_fields ?? [];
    }

    get hasMissingFields() {
        return this.missingFields.length > 0;
    }

    get orderPreviewId() {
        return this.args.task?.uuid ?? this.args.task?.id ?? this.preview?.key ?? 'ai-order-preview';
    }

    get orderTypeLabel() {
        return this.titleize(this.draft.type ?? this.draft.order_config_name ?? this.draft.order_config_uuid) ?? 'Select order type';
    }

    get pickupLabel() {
        return this.addressLabel(this.payload.pickup) ?? this.payload.pickup_query ?? 'Add pickup';
    }

    get dropoffLabel() {
        return this.addressLabel(this.payload.dropoff) ?? this.payload.dropoff_query ?? 'Add dropoff';
    }

    get driverLabel() {
        return this.draft.driver_query ?? this.draft.driver_name ?? 'Assign driver';
    }

    get vehicleLabel() {
        return this.draft.vehicle_query ?? this.draft.vehicle_name ?? 'Assign vehicle';
    }

    get notesLabel() {
        return this.draft.notes?.trim?.() || 'Add order notes';
    }

    get podMethodLabel() {
        return this.titleize(this.draft.pod_method ?? 'scan');
    }

    get isEditingOrderType() {
        return this.editingField === 'orderType';
    }

    get isEditingPickup() {
        return this.editingField === 'pickup';
    }

    get isEditingDropoff() {
        return this.editingField === 'dropoff';
    }

    get isEditingDriver() {
        return this.editingField === 'driver';
    }

    get isEditingVehicle() {
        return this.editingField === 'vehicle';
    }

    get isEditingPodMethod() {
        return this.editingField === 'podMethod';
    }

    get isEditingNotes() {
        return this.editingField === 'notes';
    }

    get isApplyDisabled() {
        return !this.isReady || this.args.isApplying || this.refreshPreview.isRunning;
    }

    addressLabel(place) {
        if (!place) {
            return null;
        }

        const values = [place.address, place.street1, place.name, place.query, place.city, place.postal_code, place.country]
            .map((value) => (value === null || value === undefined ? null : String(value).trim()))
            .filter((value) => value && !['undefined', 'null'].includes(value.toLowerCase()));

        return [...new Set(values)].slice(0, 3).join(' - ');
    }

    clone(value) {
        return JSON.parse(JSON.stringify(value ?? {}));
    }

    modelValue(model, key) {
        if (!model) {
            return null;
        }

        if (typeof model.get === 'function') {
            return model.get(key);
        }

        return get(model, key) ?? model[key];
    }

    serializeModel(model, fallback = {}) {
        if (!model) {
            return null;
        }

        if (typeof model.toJSON === 'function') {
            return {
                ...model.toJSON(),
                id: this.modelValue(model, 'id'),
                uuid: this.modelValue(model, 'uuid') ?? this.modelValue(model, 'id'),
                public_id: this.modelValue(model, 'public_id'),
                name: this.modelValue(model, 'name'),
                address: this.addressLabel(model) ?? this.modelValue(model, 'address'),
                street1: this.modelValue(model, 'street1'),
                city: this.modelValue(model, 'city'),
                postal_code: this.modelValue(model, 'postal_code'),
                country: this.modelValue(model, 'country'),
                latitude: this.modelValue(model, 'latitude'),
                longitude: this.modelValue(model, 'longitude'),
                ...fallback,
            };
        }

        return { ...model, ...fallback };
    }

    titleize(value) {
        if (!value) {
            return null;
        }

        return String(value)
            .replace(/[_-]+/g, ' ')
            .replace(/\s+/g, ' ')
            .trim()
            .replace(/\b\w/g, (char) => char.toUpperCase());
    }

    mergeDraft(updates = {}) {
        this.draft = {
            ...this.draft,
            ...updates,
            payload: {
                ...(this.draft.payload ?? {}),
                ...(updates.payload ?? {}),
            },
        };
        this.refreshPreview.perform();
    }

    @action editField(field) {
        if (this.isCancelled) {
            return;
        }

        this.editingField = field;
    }

    @action closeEditor() {
        this.editingField = null;
    }

    @task *refreshPreview() {
        yield timeout(180);
        const refreshed = yield this.args.onRefresh?.(this.args.task, this.preview, { draft: this.draft });
        if (refreshed) {
            this.preview = refreshed;
            this.draft = this.clone(refreshed.draft ?? this.draft);
        }
    }

    @action setPayloadPlace(role, place) {
        const serialized = this.serializeModel(place);
        const payload = { ...(this.draft.payload ?? {}) };

        payload[role] = serialized;
        payload[`${role}_query`] = this.addressLabel(serialized) ?? serialized?.address ?? serialized?.name ?? null;

        if (serialized?.uuid) {
            payload[`${role}_uuid`] = serialized.uuid;
        } else {
            delete payload[`${role}_uuid`];
        }

        this.mergeDraft({ payload });
        this.closeEditor();
    }

    @action setOrderConfig(orderConfig) {
        this.mergeDraft({
            order_config_uuid: this.modelValue(orderConfig, 'uuid') ?? this.modelValue(orderConfig, 'id'),
            order_config_name: this.modelValue(orderConfig, 'name'),
            type: this.modelValue(orderConfig, 'key'),
        });
        this.closeEditor();
    }

    @action setDriver(driver) {
        this.mergeDraft({
            driver: this.modelValue(driver, 'uuid') ?? this.modelValue(driver, 'id'),
            driver_query: this.modelValue(driver, 'name') ?? this.modelValue(driver, 'public_id'),
        });
        this.closeEditor();
    }

    @action setVehicle(vehicle) {
        this.mergeDraft({
            vehicle_assigned_uuid: this.modelValue(vehicle, 'uuid') ?? this.modelValue(vehicle, 'id'),
            vehicle_query: this.modelValue(vehicle, 'display_name') ?? this.modelValue(vehicle, 'name') ?? this.modelValue(vehicle, 'plate_number'),
        });
        this.closeEditor();
    }

    @action setPodRequired(value) {
        this.mergeDraft({
            pod_required: value,
            pod_method: value ? (this.draft.pod_method ?? 'scan') : null,
        });
    }

    @action setPodMethod(value) {
        this.mergeDraft({ pod_method: value });
        this.closeEditor();
    }

    @action setDispatched(value) {
        this.mergeDraft({ dispatched: value });
    }

    @action setNotes(event) {
        this.mergeDraft({ notes: event.target.value });
    }

    @action apply() {
        return this.args.onApply?.(this.args.task, this.preview, { draft: this.draft });
    }

    @action cancel() {
        return this.args.onCancel?.(this.args.task);
    }

    @action updatePreview(preview) {
        this.preview = preview;
        if (preview?.draft) {
            this.draft = this.clone(preview.draft);
        }
    }
}
