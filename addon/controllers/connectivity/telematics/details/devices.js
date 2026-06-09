import Controller from '@ember/controller';
import { inject as service } from '@ember/service';

export default class ConnectivityTelematicsDetailsDevicesController extends Controller {
    @service deviceActions;
    @service intl;

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
                label: 'Device ID',
                valuePath: 'device_id',
                resizable: true,
                sortable: true,
            },
            {
                label: 'Provider',
                valuePath: 'provider',
                cellClassNames: 'uppercase',
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
            {
                label: 'Last Seen',
                valuePath: 'last_online_at',
                resizable: true,
                sortable: true,
            },
        ];
    }
}
