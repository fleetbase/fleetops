import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { debug } from '@ember/debug';

export default class OrderDetailsRouteComponent extends Component {
    @service orderActions;
    @service modalsManager;
    @service fetch;

    get actionButtons() {
        return [
            {
                type: 'default',
                text: 'Edit',
                icon: 'pencil',
                iconPrefix: 'fas',
                permission: 'fleet-ops update-route-for order',
                disabled: this.args.resource.status === 'canceled',
                onClick: () => {
                    this.orderActions.editRoute(this.args.resource);
                },
            },
        ];
    }

    @action async viewWaypointLabel(waypoint) {
        // render dialog to display label within
        this.modalsManager.show(`modals/order-label`, {
            title: 'Waypoint Label',
            modalClass: 'modal-xl',
            acceptButtonText: 'Done',
            hideDeclineButton: true,
        });

        try {
            // load the pdf label from base64
            // eslint-disable-next-line no-undef
            const fileReader = new FileReader();
            const { data: pdfStream } = await this.fetch.get(`orders/label/${waypoint.waypoint_public_id}?format=base64`);
            // eslint-disable-next-line no-undef
            const base64 = await fetch(`data:application/pdf;base64,${pdfStream}`);
            const blob = await base64.blob();
            // load into file reader
            fileReader.onload = (event) => {
                const data = event.target.result;
                this.modalsManager.setOption('data', data);
            };
            fileReader.readAsDataURL(blob);
        } catch (err) {
            this.notifications.error('Failed to load waypoint label.');
            debug('Error loading waypoint label data: ' + err.message);
        }
    }
}
