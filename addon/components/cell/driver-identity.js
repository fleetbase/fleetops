import Component from '@glimmer/component';
import { action, get } from '@ember/object';
import config from 'ember-get-config';
import { resolveIdentityCellResource } from '../../utils/identity-cell-resource';

const DEFAULT_STATUS_TONES = {
    available: 'text-green-500',
    active: 'text-green-500',
    on_duty: 'text-green-500',
    busy: 'text-yellow-500',
    assigned: 'text-yellow-500',
    unavailable: 'text-gray-400',
    offline: 'text-gray-400',
    suspended: 'text-red-500',
};

export default class CellDriverIdentityComponent extends Component {
    get resource() {
        return resolveIdentityCellResource(this.args);
    }

    get emptyText() {
        return this.args.column?.emptyText ?? '-';
    }

    get compact() {
        return this.args.column?.compact ?? false;
    }

    get label() {
        const driver = this.resource;

        return get(driver, 'name') ?? get(driver, 'displayName') ?? get(driver, 'display_name');
    }

    get mediaUrl() {
        return get(this.resource, 'photo_url');
    }

    get fallbackImage() {
        return config?.defaultValues?.driverImage;
    }

    get hasCompactStatusDot() {
        return this.args.column?.showStatusDot ?? this.args.column?.showOnlineIndicator ?? true;
    }

    get compactStatusValue() {
        const driver = this.resource;

        return get(driver, 'online') ?? get(driver, 'status');
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

    get assignedVehicleLabel() {
        const column = this.args.column ?? {};
        const driver = this.resource;

        if (typeof column.assignedVehicleLabel === 'function') {
            return column.assignedVehicleLabel(driver, this.args.row, column);
        }

        if (column.assignedVehicleLabel !== undefined) {
            return column.assignedVehicleLabel;
        }

        if (typeof column.assignedVehiclePath === 'string') {
            return get(this.args.row, column.assignedVehiclePath) ?? get(driver, column.assignedVehiclePath);
        }

        return get(driver, 'vehicle_assigned.display_name') ?? get(driver, 'vehicle.display_name') ?? get(driver, 'vehicle_name');
    }

    get column() {
        return {
            ...(this.args.column ?? {}),
            labelPath: 'name',
            mediaPath: 'photo_url',
            fallbackImage: config?.defaultValues?.driverImage,
            statusPath: 'status',
            onlinePath: 'online',
            showStatusBadge: this.args.column?.showStatusBadge ?? true,
            statusBadgeSize: this.args.column?.statusBadgeSize ?? 'xxs',
            statusBadgeWrapperClass: this.args.column?.statusBadgeWrapperClass ?? 'resource-identity-status-badge driver-identity-status-badge order-first',
            metaPaths: [
                {
                    value: (driver) => get(driver, 'vehicle_assigned.display_name') ?? get(driver, 'vehicle.display_name') ?? get(driver, 'vehicle_name'),
                    icon: 'car',
                    style: 'badge',
                    class: 'max-w-[12rem]',
                },
            ],
            statusToneMap: {
                available: 'text-green-500',
                active: 'text-green-500',
                on_duty: 'text-green-500',
                busy: 'text-yellow-500',
                assigned: 'text-yellow-500',
                unavailable: 'text-gray-400',
                offline: 'text-gray-400',
                suspended: 'text-red-500',
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
