import Controller from '@ember/controller';
import { inject as service } from '@ember/service';

export default class ConnectivityTelematicsDetailsAttachmentsController extends Controller {
    @service deviceActions;
    @service intl;

    get unattachedDevices() {
        return Array.from(this.model ?? []).filter((device) => !device.attachable_uuid);
    }

    get columns() {
        return [
            {
                sticky: true,
                label: this.intl.t('column.name'),
                valuePath: 'displayName',
                cellComponent: 'table/cell/anchor',
                action: this.deviceActions.transition.view,
                permission: 'fleet-ops view device',
                resizable: true,
                sortable: true,
            },
            {
                label: 'Provider ID',
                valuePath: 'device_id',
                resizable: true,
                sortable: true,
            },
            {
                label: 'Attached Vehicle',
                valuePath: 'attached_to_name',
                resizable: true,
                sortable: true,
            },
            {
                label: this.intl.t('column.status'),
                valuePath: 'status',
                cellComponent: 'table/cell/status',
                resizable: true,
                sortable: true,
            },
        ];
    }
}
