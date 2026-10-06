import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

/**
 * Instance-wide defaults for the generic customer tracking page: whether it is on, and the
 * branding it shows before a lookup (and for companies without their own).
 */
export default class AdminTrackingPageSettingsComponent extends Component {
    @service fetch;
    @service notifications;
    @tracked genericEnabled = true;
    @tracked displayName = '';
    @tracked accent = '#1F5FA8';
    @tracked supportPhone = '';
    @tracked supportEmail = '';
    @tracked website = '';
    @tracked poweredBy = true;

    constructor() {
        super(...arguments);
        this.loadSettings.perform();
    }

    @task *loadSettings() {
        try {
            const settings = yield this.fetch.get('fleet-ops/settings/admin-tracking-page-settings');
            this.genericEnabled = settings?.generic_page?.enabled ?? true;
            this.displayName = settings?.branding?.display_name ?? '';
            this.accent = settings?.branding?.accent ?? '#1F5FA8';
            this.supportPhone = settings?.branding?.support_phone ?? '';
            this.supportEmail = settings?.branding?.support_email ?? '';
            this.website = settings?.branding?.website ?? '';
            this.poweredBy = settings?.branding?.powered_by ?? true;
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action setValue(property, event) {
        this[property] = event.target.value;
    }

    @task *saveSettings() {
        try {
            yield this.fetch.post('fleet-ops/settings/admin-tracking-page-settings', {
                trackingPage: {
                    generic_page: { enabled: this.genericEnabled },
                    branding: {
                        display_name: this.displayName,
                        accent: this.accent,
                        support_phone: this.supportPhone,
                        support_email: this.supportEmail,
                        website: this.website,
                        powered_by: this.poweredBy,
                    },
                },
            });

            this.notifications.success('Tracking page defaults saved.');
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
}
