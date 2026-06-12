import Component from '@glimmer/component';
import { action } from '@ember/object';

const COMPLETE_ORDER_STATUSES = ['completed', 'delivered'];
const ACTIVE_ORDER_STATUSES = ['dispatched', 'started', 'in_progress', 'driver_enroute', 'driver_nearby'];

export default class OrderCardComponent extends Component {
    get order() {
        return this.args.order ?? this.args.resource;
    }

    get variant() {
        return this.args.variant ?? 'default';
    }

    get isCompact() {
        return this.variant === 'compact';
    }

    get isSelection() {
        return this.variant === 'selection';
    }

    get isInteractive() {
        return Boolean(this.args.interactive && !this.args.disabled);
    }

    get classNames() {
        return [
            'order-card',
            `order-card-${this.variant}`,
            this.args.selected ? 'is-selected' : null,
            this.args.disabled ? 'is-disabled' : null,
            this.args.selectable ? 'is-selectable' : null,
            this.isInteractive ? 'is-interactive' : null,
        ]
            .filter(Boolean)
            .join(' ');
    }

    get orderLabel() {
        const trackingNumber = this.order?.tracking_number;

        return trackingNumber?.tracking_number ?? trackingNumber ?? this.order?.tracking ?? this.order?.public_id ?? this.order?.id;
    }

    get orderTypeLabel() {
        return this.order?.type;
    }

    get orderStops() {
        const payload = this.order?.payload ?? {};
        const stops = [];

        if (payload.pickup) {
            stops.push({ type: 'pickup', place: payload.pickup });
        }

        (payload.waypoints ?? []).forEach((waypoint, index) => {
            stops.push({
                type: waypoint.type ?? 'waypoint',
                place: waypoint.place ?? waypoint,
                index: index + 1,
            });
        });

        if (payload.dropoff) {
            stops.push({ type: 'dropoff', place: payload.dropoff });
        }

        if (payload.return) {
            stops.push({ type: 'return', place: payload.return });
        }

        return stops.filter((stop) => this.addressForPlace(stop.place));
    }

    get orderOrigin() {
        return this.orderStops[0];
    }

    get orderDestination() {
        return this.orderStops[this.orderStops.length - 1];
    }

    get orderOriginAddress() {
        return this.addressForPlace(this.orderOrigin?.place);
    }

    get orderDestinationAddress() {
        return this.addressForPlace(this.orderDestination?.place);
    }

    get orderStopCount() {
        return this.orderStops.length;
    }

    get middleStopCount() {
        return Math.max(this.orderStopCount - 2, 0);
    }

    get isMultiStopOrder() {
        return this.orderStopCount > 2;
    }

    get hasOrderStops() {
        return this.orderStopCount > 0;
    }

    get destinationLabelKey() {
        return this.orderDestination?.type === 'return' ? 'order.card.return' : 'order.card.dropoff';
    }

    get orderProgressClass() {
        const status = this.order?.status;

        if (COMPLETE_ORDER_STATUSES.includes(status)) {
            return 'is-complete';
        }

        if (ACTIVE_ORDER_STATUSES.includes(status)) {
            return 'is-active';
        }

        return 'is-pending';
    }

    get scheduledAt() {
        return this.order?.scheduled_at ?? this.order?.scheduledAt;
    }

    get createdAt() {
        return this.order?.created_at ?? this.order?.createdAt;
    }

    get orderDriverLabel() {
        return this.order?.driver_assigned?.name ?? this.order?.driver_name ?? this.order?.driver?.name;
    }

    get orderVehicleLabel() {
        return this.order?.vehicle_assigned?.displayName ?? this.order?.vehicle_assigned?.display_name ?? this.order?.vehicle_name ?? this.order?.vehicle?.displayName;
    }

    get orderCustomerLabel() {
        return this.order?.customer?.name ?? this.order?.customer_name;
    }

    get showStatus() {
        return this.argOrDefault('showStatus', true);
    }

    get showType() {
        return this.argOrDefault('showType', !this.isCompact);
    }

    get showRoute() {
        return this.argOrDefault('showRoute', !this.isCompact);
    }

    get showProgress() {
        return this.argOrDefault('showProgress', !this.isCompact && !this.isSelection);
    }

    get showMeta() {
        return this.argOrDefault('showMeta', true);
    }

    get showDriver() {
        return this.argOrDefault('showDriver', true);
    }

    get showVehicle() {
        return this.argOrDefault('showVehicle', true);
    }

    get showCustomer() {
        return this.argOrDefault('showCustomer', false);
    }

    get showScheduledAt() {
        return this.argOrDefault('showScheduledAt', true);
    }

    get metaItems() {
        const items = [];

        if (this.showScheduledAt && (this.scheduledAt || this.createdAt)) {
            items.push({
                key: 'scheduled',
                icon: 'calendar',
                value: this.scheduledAt ?? this.createdAt,
                isDate: true,
            });
        }

        if (this.showDriver && this.orderDriverLabel) {
            items.push({ key: 'driver', icon: 'id-card', value: this.orderDriverLabel });
        }

        if (this.showVehicle && this.orderVehicleLabel) {
            items.push({ key: 'vehicle', icon: 'truck', value: this.orderVehicleLabel });
        }

        if (this.showCustomer && this.orderCustomerLabel) {
            items.push({ key: 'customer', icon: 'user', value: this.orderCustomerLabel });
        }

        return items;
    }

    get hasMeta() {
        return this.metaItems.length > 0;
    }

    get tabIndex() {
        return this.isInteractive ? '0' : null;
    }

    get role() {
        return this.isInteractive ? 'button' : null;
    }

    argOrDefault(argName, defaultValue) {
        return typeof this.args[argName] === 'boolean' ? this.args[argName] : defaultValue;
    }

    addressForPlace(place) {
        return place?.address ?? place?.address_html ?? place?.street1 ?? place?.name;
    }

    @action handleClick() {
        if (!this.isInteractive || typeof this.args.onClick !== 'function') {
            return;
        }

        this.args.onClick(this.order);
    }

    @action handleKeyDown(event) {
        if (!this.isInteractive || !['Enter', ' '].includes(event.key)) {
            return;
        }

        event.preventDefault();
        this.handleClick();
    }

    @action handleSelect(event) {
        event?.stopPropagation();

        if (this.args.disabled || typeof this.args.onSelect !== 'function') {
            return;
        }

        this.args.onSelect(this.order);
    }
}
