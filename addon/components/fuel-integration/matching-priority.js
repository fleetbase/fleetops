import Component from '@glimmer/component';
import { action } from '@ember/object';

const MATCHING_FIELDS = [
    {
        key: 'plate_number',
        label: 'Plate number',
        source: 'Provider plate number',
        target: 'Vehicle plate number',
        description: 'Best default for fuel card imports. Matches license plates with normalized spacing and casing.',
    },
    {
        key: 'internal_id',
        label: 'Internal ID',
        source: 'Provider internal fleet number',
        target: 'Vehicle internal ID',
        description: 'Use when the provider fleet number is the same identifier operators maintain on the vehicle.',
    },
    {
        key: 'vin',
        label: 'VIN',
        source: 'Provider VIN',
        target: 'Vehicle VIN',
        description: 'Matches the vehicle identification number when the provider sends it with the transaction.',
    },
    {
        key: 'serial_number',
        label: 'Serial number',
        source: 'Provider serial number',
        target: 'Vehicle serial number',
        description: 'Matches manufacturer or fleet serial numbers recorded on the vehicle.',
    },
    {
        key: 'call_sign',
        label: 'Call sign',
        source: 'Provider call sign',
        target: 'Vehicle call sign',
        description: 'Matches operational call signs when those are shared with the fuel provider.',
    },
    {
        key: 'fuel_card_number',
        label: 'Fuel card number',
        source: 'Provider fuel card number',
        target: 'Vehicle fuel card number',
        description: 'Use when each vehicle has a dedicated fuel card number saved on the vehicle record.',
    },
    {
        key: 'trip_number',
        label: 'Trip number / order reference',
        source: 'Provider trip/order reference',
        target: 'Order public ID, internal ID, or tracking number',
        description: 'Links the transaction to an order when the provider sends a trip or order reference. It does not identify the vehicle by itself.',
    },
    {
        key: 'provider_vehicle_id',
        label: 'Provider vehicle ID',
        source: 'Provider-specific vehicle ID',
        target: 'Vehicle provider metadata',
        description: 'Advanced fallback only. Use it when provider vehicle IDs have been intentionally stored on vehicle metadata.',
    },
];

const DEFAULT_MATCHING_ORDER = ['plate_number', 'internal_id', 'vin', 'serial_number', 'call_sign', 'fuel_card_number', 'trip_number'];
const LEGACY_DEFAULT_MATCHING_ORDER = ['provider_vehicle_id', 'internal_number', 'structure_number', 'plate_number', 'vehicle_card_id', 'trip_number'];
const LEGACY_FIELD_ALIASES = {
    internal_number: 'internal_id',
    vehicle_card_id: 'fuel_card_number',
};

export default class FuelIntegrationMatchingPriorityComponent extends Component {
    fields = MATCHING_FIELDS;

    get order() {
        if (Array.isArray(this.args.order)) {
            if (this.args.order.join('|') === LEGACY_DEFAULT_MATCHING_ORDER.join('|')) {
                return DEFAULT_MATCHING_ORDER;
            }

            return Array.from(this.args.order)
                .map((key) => LEGACY_FIELD_ALIASES[key] ?? key)
                .filter((key, index, order) => key && order.indexOf(key) === index);
        }

        return DEFAULT_MATCHING_ORDER;
    }

    get selectedFields() {
        const lastIndex = this.order.length - 1;

        return this.order
            .map((key, index) => {
                const field = this.fields.find((option) => option.key === key);
                if (!field) {
                    return null;
                }

                return {
                    ...field,
                    index,
                    priority: index + 1,
                    canMoveUp: index > 0,
                    canMoveDown: index < lastIndex,
                };
            })
            .filter(Boolean);
    }

    get availableFields() {
        const selected = new Set(this.order);
        return this.fields.filter((field) => !selected.has(field.key));
    }

    get hasSelectedFields() {
        return this.selectedFields.length > 0;
    }

    updateOrder(order) {
        this.args.onChange?.(order);
    }

    @action addField(field) {
        if (this.order.includes(field.key)) {
            return;
        }

        this.updateOrder([...this.order, field.key]);
    }

    @action removeField(field) {
        this.updateOrder(this.order.filter((key) => key !== field.key));
    }

    @action moveField(field, direction) {
        const currentIndex = this.order.indexOf(field.key);
        const nextIndex = currentIndex + direction;

        if (currentIndex < 0 || nextIndex < 0 || nextIndex >= this.order.length) {
            return;
        }

        const nextOrder = [...this.order];
        [nextOrder[currentIndex], nextOrder[nextIndex]] = [nextOrder[nextIndex], nextOrder[currentIndex]];
        this.updateOrder(nextOrder);
    }
}
