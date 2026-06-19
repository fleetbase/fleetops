import Component from '@glimmer/component';
import { action, get } from '@ember/object';
import config from 'ember-get-config';
import { resolveIdentityCellResource } from '../../utils/identity-cell-resource';

const DEFAULT_STATUS_TONES = {
    available: 'text-green-500',
    active: 'text-green-500',
    in_service: 'text-green-500',
    maintenance: 'text-yellow-500',
    unavailable: 'text-gray-400',
    inactive: 'text-gray-400',
    out_of_service: 'text-red-500',
};

export default class CellVehicleIdentityComponent extends Component {
    get resource() {
        return resolveIdentityCellResource(this.args);
    }

    get emptyText() {
        return this.args.column?.emptyText ?? '-';
    }

    get compact() {
        return this.args.column?.compact ?? false;
    }

    get showStatus() {
        return this.args.column?.showStatus ?? true;
    }

    get label() {
        const vehicle = this.resource;

        return get(vehicle, 'displayName') ?? get(vehicle, 'display_name') ?? get(vehicle, 'name');
    }

    get mediaUrl() {
        return get(this.resource, 'photo_url');
    }

    get fallbackImage() {
        return config?.defaultValues?.vehicleAvatar;
    }

    get hasCompactStatusDot() {
        return this.args.column?.showStatusDot ?? this.args.column?.showOnlineIndicator ?? true;
    }

    get compactStatusValue() {
        const vehicle = this.resource;

        return get(vehicle, 'online') ?? get(vehicle, 'status');
    }

    get compactStatusToneClass() {
        const value = this.compactStatusValue;
        const statusToneMap = {
            ...DEFAULT_STATUS_TONES,
            ...(this.args.column?.statusToneMap ?? {}),
        };

        if (typeof this.args.column?.statusToneClass === 'function') {
            return this.args.column.statusToneClass(value, this.resource, this.args.column);
        }

        if (typeof value === 'boolean') {
            return value ? 'text-green-500' : 'text-yellow-200';
        }

        return statusToneMap[value] ?? statusToneMap[String(value ?? '').toLowerCase()] ?? 'text-gray-400';
    }

    get compactStatusDotClass() {
        return this.compactStatusToneClass;
    }

    get assignedDriverLabel() {
        const vehicle = this.resource;

        return get(vehicle, 'driver.displayName') ?? get(vehicle, 'driver.display_name') ?? get(vehicle, 'driver.name') ?? get(vehicle, 'driver_name');
    }

    get column() {
        return {
            ...(this.args.column ?? {}),
            labelPath: (vehicle) => get(vehicle, 'displayName') ?? get(vehicle, 'display_name') ?? get(vehicle, 'name'),
            mediaPath: 'photo_url',
            fallbackImage: config?.defaultValues?.vehicleAvatar,
            statusPath: this.showStatus ? 'status' : undefined,
            onlinePath: 'online',
            showStatusBadge: this.showStatus ? (this.args.column?.showStatusBadge ?? true) : false,
            statusBadgeSize: this.args.column?.statusBadgeSize ?? 'xxs',
            statusBadgeWrapperClass: this.args.column?.statusBadgeWrapperClass ?? 'resource-identity-status-badge vehicle-identity-status-badge',
            metaPaths: [
                {
                    value: (vehicle) => get(vehicle, 'plate_number') ?? get(vehicle, 'call_sign') ?? get(vehicle, 'vehicle_number') ?? get(vehicle, 'public_id'),
                    icon: 'id-card',
                    style: 'badge',
                    class: 'max-w-[12rem]',
                },
                {
                    value: (vehicle) => get(vehicle, 'driver.displayName') ?? get(vehicle, 'driver.display_name') ?? get(vehicle, 'driver.name') ?? get(vehicle, 'driver_name'),
                    icon: 'user',
                    style: 'badge',
                    class: 'max-w-[12rem]',
                },
            ],
            statusToneMap: {
                available: 'text-green-500',
                active: 'text-green-500',
                in_service: 'text-green-500',
                maintenance: 'text-yellow-500',
                unavailable: 'text-gray-400',
                inactive: 'text-gray-400',
                out_of_service: 'text-red-500',
            },
        };
    }

    @action onClick(event) {
        const { column, onClick } = this.args;
        const resource = this.resource;

        if (typeof onClick === 'function') {
            onClick(resource, event);
        }

        if (typeof column?.onClick === 'function') {
            column.onClick(resource, event);
        }

        if (typeof column?.action === 'function') {
            column.action(resource, event);
        }
    }
}
