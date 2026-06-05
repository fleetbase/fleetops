import Controller from '@ember/controller';
import { inject as service } from '@ember/service';

export default class ConnectivityTelematicsIndexDetailsEventsController extends Controller {
    @service deviceEventActions;
    @service intl;

    get columns() {
        return [
            {
                sticky: true,
                label: 'Event',
                valuePath: 'event_type',
                cellComponent: 'table/cell/anchor',
                action: this.deviceEventActions.transition.view,
                permission: 'fleet-ops view device-event',
                resizable: true,
                sortable: true,
            },
            {
                label: 'Device',
                valuePath: 'device.displayName',
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
                label: 'Severity',
                valuePath: 'severity',
                resizable: true,
                sortable: true,
            },
            {
                label: this.intl.t('column.created-at'),
                valuePath: 'createdAt',
                sortParam: 'created_at',
                resizable: true,
                sortable: true,
            },
        ];
    }
}
