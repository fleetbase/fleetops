import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class OrderConfigManagerDetailsComponent extends Component {
    @service modalsManager;
    @service notifications;
    @service intl;
    @service store;
    @tracked config;

    constructor(owner, { config }) {
        super(...arguments);
        this.config = config;
    }

    @action save() {
        return this.config
            .save()
            .then(() => {
                this.notifications.success(this.intl.t('fleet-ops.component.order-config-manager.saved-success-message', { orderConfigName: this.config.name }));
                if (typeof this.args.onConfigUpdated === 'function') {
                    this.args.onConfigUpdated(this.config);
                }
            })
            .catch((error) => {
                this.notifications.serverError(error);
            });
    }

    @action delete() {
        this.modalsManager.confirm({
            title: this.intl.t('fleet-ops.component.order-config-manager.details.delete.delete-title'),
            body: this.intl.t('fleet-ops.component.order-config-manager.details.delete.delete-body-message'),
            acceptButtonText: this.intl.t('fleet-ops.component.order-config-manager.details.delete.confirm-delete'),
            confirm: () => {
                if (typeof this.args.onConfigDeleting === 'function') {
                    this.args.onConfigDeleting(this.config);
                }

                return this.config.destroyRecord().then(() => {
                    if (typeof this.args.onConfigDeleted === 'function') {
                        this.args.onConfigDeleted(this.config);
                    }
                });
            },
        });
    }
}
