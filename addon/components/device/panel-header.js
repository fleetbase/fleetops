import Component from '@glimmer/component';
import { get } from '@ember/object';

export default class DevicePanelHeaderComponent extends Component {
    get resource() {
        return this.args.resource ?? {};
    }

    get name() {
        return (
            get(this.resource, 'displayName') ??
            get(this.resource, 'display_name') ??
            get(this.resource, 'name') ??
            get(this.resource, 'device_id') ??
            get(this.resource, 'imei') ??
            get(this.resource, 'serial_number') ??
            get(this.resource, 'public_id') ??
            '-'
        );
    }

    get connectionStatus() {
        return get(this.resource, 'connection_status') ?? get(this.resource, 'status') ?? (get(this.resource, 'is_online') || get(this.resource, 'online') ? 'online' : 'offline');
    }

    get provider() {
        return get(this.resource, 'telematic.name') ?? get(this.resource, 'telematic_name') ?? get(this.resource, 'provider');
    }

    get type() {
        return get(this.resource, 'type');
    }

    get identifier() {
        return get(this.resource, 'imei') ?? get(this.resource, 'serial_number') ?? get(this.resource, 'device_id') ?? get(this.resource, 'public_id');
    }

    get attachedVehicle() {
        return (
            get(this.resource, 'attached_to_name') ??
            get(this.resource, 'attachable.displayName') ??
            get(this.resource, 'attachable.display_name') ??
            get(this.resource, 'attachable.name') ??
            get(this.resource, 'attachable_uuid')
        );
    }

    get lastOnlineAt() {
        return get(this.resource, 'last_online_at') ?? get(this.resource, 'lastOnlineAt');
    }
}
