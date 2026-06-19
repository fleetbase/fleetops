import Component from '@glimmer/component';
import { action, get } from '@ember/object';
import config from 'ember-get-config';
import { resolveIdentityCellResource } from '../../utils/identity-cell-resource';

const DEFAULT_STATUS_TONES = {
    online: 'text-green-500',
    active: 'text-green-500',
    recently_offline: 'text-yellow-500',
    offline: 'text-gray-400',
    long_offline: 'text-gray-400',
    never_connected: 'text-gray-400',
    inactive: 'text-gray-400',
    error: 'text-red-500',
};

export default class CellDeviceIdentityComponent extends Component {
    get resource() {
        return resolveIdentityCellResource(this.args);
    }

    get emptyText() {
        return this.args.column?.emptyText ?? '-';
    }

    get showStatus() {
        return this.args.column?.showStatus ?? true;
    }

    get compact() {
        return this.args.column?.compact ?? false;
    }

    get label() {
        const device = this.resource;

        return get(device, 'displayName') ?? get(device, 'display_name') ?? get(device, 'name') ?? get(device, 'device_id') ?? get(device, 'imei') ?? get(device, 'serial_number');
    }

    get mediaUrl() {
        return get(this.resource, 'photo_url');
    }

    get fallbackImage() {
        return config?.defaultValues?.placeholderImage;
    }

    get hasCompactStatusDot() {
        return this.args.column?.showStatusDot ?? this.args.column?.showOnlineIndicator ?? true;
    }

    get compactStatusValue() {
        const device = this.resource;

        return get(device, 'is_online') ?? get(device, 'connection_status') ?? get(device, 'status');
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

    get column() {
        return {
            ...(this.args.column ?? {}),
            labelPath: (device) =>
                get(device, 'displayName') ?? get(device, 'display_name') ?? get(device, 'name') ?? get(device, 'device_id') ?? get(device, 'imei') ?? get(device, 'serial_number'),
            mediaPath: 'photo_url',
            fallbackImage: config?.defaultValues?.placeholderImage,
            statusPath: this.showStatus ? (device) => get(device, 'connection_status') ?? get(device, 'status') : undefined,
            onlinePath: 'is_online',
            showStatusBadge: this.showStatus ? (this.args.column?.showStatusBadge ?? true) : false,
            statusBadgeSize: this.args.column?.statusBadgeSize ?? 'xxs',
            statusBadgeWrapperClass: this.args.column?.statusBadgeWrapperClass ?? 'resource-identity-status-badge device-identity-status-badge',
            metaPaths: [
                {
                    value: (device) => get(device, 'imei') ?? get(device, 'device_id') ?? get(device, 'ident') ?? get(device, 'serial_number'),
                    icon: 'microchip',
                    style: 'badge',
                    class: 'max-w-[12rem]',
                },
            ],
            statusToneMap: {
                online: 'text-green-500',
                active: 'text-green-500',
                recently_offline: 'text-yellow-500',
                offline: 'text-gray-400',
                long_offline: 'text-gray-400',
                never_connected: 'text-gray-400',
                inactive: 'text-gray-400',
                error: 'text-red-500',
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
