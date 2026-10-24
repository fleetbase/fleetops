import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

/**
 * App Review access for the organization's Navigator app: lets Apple and Google reviewers sign
 * in with a fixed test number (never texted) and a code the owner gives the app store. Core
 * (`int/v1/review-access/navigator`) keeps the code hashed, so it is shown only in the
 * response that created it.
 */
export default class NavigatorAppReviewSettingsComponent extends Component {
    @service fetch;
    @service intl;
    @service currentUser;
    @service notifications;
    @service modalsManager;

    @tracked status = null;
    @tracked code = null;

    permission = 'fleet-ops update navigator-settings';

    constructor() {
        super(...arguments);
        this.load.perform();
    }

    get endpoint() {
        return `review-access/navigator/${this.currentUser.companyId}`;
    }

    get enabled() {
        return this.status?.enabled === true;
    }

    get codeCreatedAt() {
        const at = this.status?.code_generated_at;
        return at ? new Date(at).toLocaleString() : null;
    }

    @task *load() {
        if (!this.currentUser.companyId) return;

        try {
            const { review_access } = yield this.fetch.get(this.endpoint, {}, { namespace: 'int/v1' });
            this.status = review_access;
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task *save(payload, message) {
        try {
            const { review_access } = yield this.fetch.put(this.endpoint, payload, { namespace: 'int/v1' });
            const { code, ...status } = review_access;
            this.status = status;
            if (code) this.code = code;
            if (!status.enabled) this.code = null;
            this.notifications.success(message);
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action turnOn() {
        this.save.perform({ enabled: true }, this.intl.t('navigator-app-review-settings.turned-on'));
    }

    @action turnOff() {
        this.modalsManager.confirm({
            title: this.intl.t('navigator-app-review-settings.turn-off-title'),
            body: this.intl.t('navigator-app-review-settings.turn-off-body'),
            acceptButtonText: this.intl.t('navigator-app-review-settings.turn-off'),
            acceptButtonType: 'danger',
            confirm: async (modal) => {
                modal.startLoading();
                await this.save.perform({ enabled: false }, this.intl.t('navigator-app-review-settings.turned-off'));
                modal.done();
            },
        });
    }

    @action regenerate() {
        this.modalsManager.confirm({
            title: this.intl.t('navigator-app-review-settings.regenerate-title'),
            body: this.intl.t('navigator-app-review-settings.regenerate-body'),
            acceptButtonText: this.intl.t('navigator-app-review-settings.regenerate'),
            acceptButtonType: 'danger',
            acceptButtonIcon: 'rotate',
            confirm: async (modal) => {
                modal.startLoading();
                await this.save.perform({ regenerate_code: true }, this.intl.t('navigator-app-review-settings.regenerated'));
                modal.done();
            },
        });
    }
}
