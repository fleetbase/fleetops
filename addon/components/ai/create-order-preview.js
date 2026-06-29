import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action, get } from '@ember/object';
import { task, timeout } from 'ember-concurrency';

export default class AiCreateOrderPreviewComponent extends Component {
    @tracked draft = this.clone(this.args.preview?.draft ?? {});
    @tracked preview = this.args.preview;

    get payload() {
        return this.draft.payload ?? {};
    }

    get routeStops() {
        return this.preview?.route_preview?.stops ?? [];
    }

    get coordinates() {
        return this.routeStops.filter(
            (stop) =>
                stop.latitude !== null &&
                stop.latitude !== undefined &&
                stop.longitude !== null &&
                stop.longitude !== undefined &&
                Number.isFinite(Number(stop.latitude)) &&
                Number.isFinite(Number(stop.longitude))
        );
    }

    get mapCenter() {
        const stop = this.coordinates[0];

        return {
            latitude: Number(stop?.latitude ?? 1.3521),
            longitude: Number(stop?.longitude ?? 103.8198),
        };
    }

    get canShowMap() {
        return this.coordinates.length >= 2;
    }

    get routeLineCoordinates() {
        return this.coordinates.map((stop) => stop.coordinates);
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
    }

    @action setOrderConfig(orderConfig) {
        this.mergeDraft({
            order_config_uuid: this.modelValue(orderConfig, 'uuid') ?? this.modelValue(orderConfig, 'id'),
            type: this.modelValue(orderConfig, 'key'),
        });
    }

    @action setDriver(driver) {
        this.mergeDraft({
            driver: this.modelValue(driver, 'uuid') ?? this.modelValue(driver, 'id'),
            driver_query: this.modelValue(driver, 'name') ?? this.modelValue(driver, 'public_id'),
        });
    }

    @action setVehicle(vehicle) {
        this.mergeDraft({
            vehicle_assigned_uuid: this.modelValue(vehicle, 'uuid') ?? this.modelValue(vehicle, 'id'),
            vehicle_query: this.modelValue(vehicle, 'display_name') ?? this.modelValue(vehicle, 'name') ?? this.modelValue(vehicle, 'plate_number'),
        });
    }

    @action setPodRequired(value) {
        this.mergeDraft({
            pod_required: value,
            pod_method: value ? (this.draft.pod_method ?? 'scan') : null,
        });
    }

    @action setPodMethod(value) {
        this.mergeDraft({ pod_method: value });
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
