import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';

export default class ModalsUpdateOrderActivityComponent extends Component {
    @service fetch;
    @service notifications;
    @service modalsManager;
    @tracked activityOptions = [];
    @tracked order;
    @tracked selectedActivity;
    @tracked customActivity;

    constructor(owner, { options = {} }) {
        super(...arguments);
        this.order = options.order;
        this.loadActivity.perform();
    }

    @task *loadActivity() {
        try {
            const activityOptions = yield this.fetch.get(`orders/next-activity/${this.order.id}`);
            this.activityOptions = activityOptions;
            return activityOptions;
        } catch (err) {
            this.notifications.serverError(err);
        }
    }

    @task *submitActivity(modal) {
        modal.startLoading();

        const activity = this.selectedActivity === 'custom' ? this.customActivity : this.activityOptions[this.selectedActivity];
        if (this.selectedActivity === 'custom' && !activity.status && !activity.details && !activity.code) {
            modal.stopLoading();
            return this.notifications.warning(this.intl.t('fleet-ops.operations.orders.index.view.invalid-warning'));
        }

        try {
            yield this.fetch.patch(`orders/update-activity/${this.order.id}`, { activity });
            this.modalsManager.setOption('activityCreated', activity);
            this.notifications.success(`Order activity has been updated to ${activity.status}`);
        } catch (error) {
            this.notifications.serverError(error);
        } finally {
            modal.stopLoading();
            modal.done();
        }
    }
}
