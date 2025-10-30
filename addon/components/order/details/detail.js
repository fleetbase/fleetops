import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class OrderDetailsDetailComponent extends Component {
    @service orderActions;
    @service driverActions;
    @service leafletMapManager;
    @service intl;

    get actionButtons() {
        return [
            {
                type: 'default',
                text: this.intl.t('common.edit'),
                icon: 'pencil',
                iconPrefix: 'fas',
                permission: 'fleet-ops update order',
                disabled: this.args.resource.status === 'canceled',
                onClick: () => {
                    this.orderActions.editOrderDetails(this.args.resource);
                },
            },
            {
                type: 'default',
                text: this.args.resource.driver_assigned_uuid ? this.intl.t('order.actions.change-driver') : this.intl.t('order.actions.assign-driver'),
                icon: 'edit',
                iconPrefix: 'fas',
                permission: 'fleet-ops assign-driver-for order',
                disabled: this.args.resource.status === 'canceled',
                onClick: () => {
                    this.orderActions.assignDriver(this.args.resource);
                },
            },
        ];
    }

    @action focusOrderAssignedDriver(driver) {
        console.log('[focusOrderAssignedDriver]', ...arguments);
        this.driverActions.panel.view(driver);
        this.leafletMapManager.map?.flyTo(driver.coordinates, 18);
    }
}
