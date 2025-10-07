import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { debug } from '@ember/debug';
import { task } from 'ember-concurrency';

export default class OrderDetailsPayloadComponent extends Component {
    @service entityActions;
    @service modalsManager;
    @service notifications;
    @service store;
    @service fetch;

    get actionButtons() {
        return [
            {
                type: 'default',
                text: 'Add entity',
                icon: 'plus',
                iconPrefix: 'fas',
                permission: 'fleet-ops update order',
                wrapperClass: this.args.resource.isMultiDropOrder ? 'hidden' : 'flex',
                disabled: this.args.resource.status === 'canceled',
                onClick: () => this.addEntity.perform(),
            },
        ];
    }

    @task *addEntity(destination) {
        try {
            const entity = this.store.createRecord('entity', {
                payload_uuid: this.args.resource.payload.id,
                destination_uuid: destination ? destination?.id : null,
            });

            if (!this.args.resource.isNew) {
                yield entity.save();
            }
            this.args.resource.payload.entities.pushObject(entity);
        } catch (err) {
            debug('Failed to add entity to order: ' + err.message);
            this.notifications.error('Unable to add new entity to order.');
        }
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
