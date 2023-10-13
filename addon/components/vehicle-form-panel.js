import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class VehicleFormPanelComponent extends Component {
    @service store;
    @service notifications;
    @service hostRouter;
    @service loader;
    @service contextPanel;

    constructor() {
        super(...arguments);
        this.vehicle = this.args.vehicle;
        this.applyDynamicArguments();
    }

    applyDynamicArguments() {
        // Apply context if available
        if (this.args.context) {
            this.vehicle = this.args.context;
        }

        // Apply dynamic arguments if available
        if (this.args.dynamicArgs) {
            const keys = Object.keys(this.args.dynamicArgs);

            keys.forEach((key) => {
                this[key] = this.args.dynamicArgs[key];
            });
        }
    }

    @action save() {
        const { onAfterSave } = this.args;
        const { vehicle } = this;

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
        this.contextPanel.focus(this.vehicle, 'viewing');
    }
}
