import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class DriverFormPanelComponent extends Component {
    @service store;
    @service notifications;
    @service hostRouter;
    @service contextPanel;
    @service loader;
    @tracked driver;

    constructor() {
        super(...arguments);
        this.driver = this.args.driver;
        this.applyDynamicArguments();
    }

    applyDynamicArguments() {
        // Apply context if available
        if (this.args.context) {
            this.driver = this.args.context;
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
        const { driver } = this;
        const { onAfterSave } = this.args;

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
        this.contextPanel.focus(this.driver, 'viewing');
    }
}
