import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

/**
 * Equipment currently equipped to a vehicle, with attach/detach controls.
 */
export default class VehicleDetailsEquipmentComponent extends Component {
    @service store;
    @service notifications;
    @service equipmentActions;
    @service vehicleActions;
    @tracked equipment = [];

    get vehicle() {
        return this.args.resource ?? this.args.vehicle;
    }

    constructor() {
        super(...arguments);
        this.loadEquipment.perform();
    }

    @task *loadEquipment() {
        try {
            const equipment = yield this.store.query('equipment', {
                equipable_type: 'fleet-ops:vehicle',
                equipable: this.vehicle.public_id ?? this.vehicle.id,
                sort: '-created_at',
            });

            this.equipment = Array.from(equipment ?? []);
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action attach() {
        return this.vehicleActions.attachEquipment(this.vehicle, { callback: () => this.loadEquipment.perform() });
    }

    @action detach(equipment) {
        return this.vehicleActions.detachEquipment(this.vehicle, equipment, { callback: () => this.loadEquipment.perform() });
    }

    @action view(equipment) {
        if (this.equipmentActions.panel?.view) {
            return this.equipmentActions.panel.view(equipment);
        }

        return this.equipmentActions.transition.view(equipment);
    }
}
