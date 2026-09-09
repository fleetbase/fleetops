import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class TrailerDetailsEquipmentComponent extends Component {
    @service store;
    @service notifications;
    @service equipmentActions;
    @service trailerActions;
    @tracked equipment = [];

    get trailer() {
        return this.args.resource;
    }

    constructor() {
        super(...arguments);
        this.loadEquipment.perform();
    }

    @task *loadEquipment() {
        try {
            const equipment = yield this.store.query('equipment', {
                equipable_type: 'fleet-ops:trailer',
                equipable: this.trailer.public_id ?? this.trailer.id,
                sort: '-created_at',
            });

            this.equipment = Array.from(equipment ?? []);
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action attach() {
        return this.trailerActions.attachEquipment(this.trailer, { callback: () => this.loadEquipment.perform() });
    }

    @action detach(equipment) {
        return this.trailerActions.detachEquipment(this.trailer, equipment, { callback: () => this.loadEquipment.perform() });
    }

    @action view(equipment) {
        if (this.equipmentActions.panel?.view) {
            return this.equipmentActions.panel.view(equipment);
        }

        return this.equipmentActions.transition.view(equipment);
    }
}
