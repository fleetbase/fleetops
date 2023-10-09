import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class DriverFormPanelComponent extends Component {
    @service store;
    @service notifications;
    @service hostRouter;
    @service loader;

    @action save() {
        const { driver, onAfterSave } = this.args;

        this.loader.showLoader('.overlay-inner-content', 'Saving driver...');

        try {
            return driver
                .save()
                .then((vehicle) => {
                    this.notifications.success(`Driver (${driver.name}) saved successfully.`);

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
        const { driver } = this.args;
        return this.hostRouter.transitionTo('console.fleet-ops.management.drivers.index.details', driver.public_id);
    }
}
