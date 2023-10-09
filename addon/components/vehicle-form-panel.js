import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class VehicleFormPanelComponent extends Component {
    @service store;
    @service notifications;
    @service hostRouter;
    @service loader;

    @action save() {
        const { vehicle, onAfterSave } = this.args;

        this.loader.showLoader('.overlay-inner-content', 'Saving vehicle...');

        try {
            return vehicle
                .save()
                .then((vehicle) => {
                    this.notifications.success(`Vehicle (${vehicle.displayName}) saved successfully.`);

                    if (typeof onAfterSave === 'function') {
                        onAfterSave(vehicle);
                    }
                })
                .catch(this.notifications.serverError)
                .finally(() => {
                    this.loader.removeLoader();
                });
        } catch (error) {
            this.loader.removeLoader();
        }
    }

    @action viewDetails() {
        const { vehicle } = this.args;
        return this.hostRouter.transitionTo('console.fleet-ops.management.vehicles.index.details', vehicle.public_id);
    }
}
