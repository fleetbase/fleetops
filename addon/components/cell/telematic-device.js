import Component from '@glimmer/component';
import { action } from '@ember/object';

export default class CellTelematicDeviceComponent extends Component {
    get device() {
        return this.args.row;
    }

    get name() {
        return this.device?.displayName ?? this.device?.display_name ?? this.device?.name ?? this.device?.device_id ?? this.device?.imei ?? this.device?.serial_number;
    }

    get identifier() {
        return this.device?.imei ?? this.device?.device_id ?? this.device?.internal_id ?? this.device?.serial_number ?? this.device?.public_id;
    }

    get imageUrl() {
        return this.device?.photo_url;
    }

    get connectionStatus() {
        return this.device?.connection_status ?? (this.device?.is_online ? 'online' : 'offline');
    }

    get isOnline() {
        return this.device?.is_online || this.connectionStatus === 'online';
    }

    @action onClick(event) {
        const { column, onClick } = this.args;

        if (typeof onClick === 'function') {
            onClick(this.device, event);
        }

        if (typeof column?.action === 'function') {
            column.action(this.device, event);
        }

        if (typeof column?.onClick === 'function') {
            column.onClick(this.device, event);
        }
    }
}
