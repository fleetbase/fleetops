import Controller from '@ember/controller';
import { inject as service } from '@ember/service';

export default class ConnectivityTelematicsIndexDetailsSensorsController extends Controller {
    @service sensorActions;
    @service intl;

    get columns() {
        return [
            {
                sticky: true,
                label: this.intl.t('column.name'),
                valuePath: 'name',
                cellComponent: 'table/cell/anchor',
                action: this.sensorActions.transition.view,
                permission: 'fleet-ops view sensor',
                resizable: true,
                sortable: true,
            },
            {
                label: 'Type',
                valuePath: 'type',
                resizable: true,
                sortable: true,
            },
            {
                label: 'Value',
                valuePath: 'last_value',
                resizable: true,
                sortable: true,
            },
            {
                label: 'Unit',
                valuePath: 'unit',
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
