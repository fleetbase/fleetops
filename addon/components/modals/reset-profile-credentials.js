import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { INTERNAL_NAMESPACE, updateProfileFromResponse } from '../../utils/profile-login-actions';

/**
 * Resets the login password of a driver, contact or customer profile.
 *
 * Options:
 * - `profile`: the driver or contact record
 * - `endpoint`: the reset credentials endpoint (internal namespace)
 * - `payload`: extra body params, or a function returning them
 * - `onPasswordResetComplete(response)`: optional callback
 */
export default class ModalsResetProfileCredentialsComponent extends Component {
    @service fetch;
    @service intl;
    @service notifications;
    @tracked options = {};
    @tracked password;
    @tracked confirmPassword;
    @tracked sendCredentials = true;
    @tracked profile;

    constructor(owner, { options }) {
        super(...arguments);
        this.profile = options.profile;
        this.options = options;
        this.setupOptions();
    }

    get extraPayload() {
        const { payload } = this.options;
        return (typeof payload === 'function' ? payload(this.profile) : payload) ?? {};
    }

    setupOptions() {
        this.options.title = this.options.title ?? this.intl.t('profile-account.prompts.reset-password-title');
        this.options.acceptButtonText = this.options.acceptButtonText ?? this.intl.t('profile-account.prompts.reset-password-accept');
        this.options.declineButtonHidden = true;
        this.options.confirm = async (modal) => {
            modal.startLoading();

            try {
                const response = await this.fetch.post(
                    this.options.endpoint,
                    {
                        ...this.extraPayload,
                        password: this.password,
                        password_confirmation: this.confirmPassword,
                        send_credentials: this.sendCredentials,
                    },
                    { namespace: INTERNAL_NAMESPACE }
                );

                updateProfileFromResponse(this.profile, response);
                this.notifications.success(this.intl.t('profile-account.prompts.reset-password-success'));

                if (typeof this.options.onPasswordResetComplete === 'function') {
                    this.options.onPasswordResetComplete(response);
                }

                modal.done();
            } catch (error) {
                this.notifications.serverError(error);
                modal.stopLoading();
            }
        };
    }
}
