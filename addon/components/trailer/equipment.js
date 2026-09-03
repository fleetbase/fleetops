import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { task } from 'ember-concurrency';
export default class TrailerEquipmentComponent extends Component {
    @service store;
    @service notifications;
    @service equipmentActions;
    @service trailerActions;
    @tracked equipment = [];
    constructor() {
        super(...arguments);
        this.load.perform();
    }
    @task *load() {
        try {
            this.equipment = yield this.store.query('equipment', {
                equipable_type: 'fleet-ops:trailer',
                equipable: this.args.resource.public_id ?? this.args.resource.id,
                sort: '-created_at',
            });
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
    attach = () => this.trailerActions.attachEquipment(this.args.resource);
    detach = async (equipment) => {
        await this.trailerActions.detachEquipment(this.args.resource, equipment);
        this.load.perform();
    };
}
