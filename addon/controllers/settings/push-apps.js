import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

const P = 'settings.push-apps';
const blankForm = () => ({
    name: '',
    status: 'active',
    apn: { key_id: '', team_id: '', app_bundle_id: '', private_key_content: '', environment: '' },
    fcm: { credentials: '', android_channel_id: '' },
});

/**
 * The organization's own apps and each one's Apple (APNs) and Firebase (FCM) settings. One
 * push app covers an app on iOS and Android. An app registers devices with its push app key;
 * secrets are entered here but never shown again, and leaving one empty when editing keeps the
 * saved one. The server answers each app's settings under `providers`, by provider key.
 */
export default class SettingsPushAppsController extends Controller {
    @service fetch;
    @service notifications;
    @service modalsManager;
    @service intl;
    @service abilities;

    @tracked pushApps = [];
    @tracked editing = null;
    @tracked form = blankForm();
    @tracked error = null;
    @tracked testing = null;
    @tracked test = { token: '', push_provider: '', environment: '' };
    @tracked testResult = null;

    get canUpdate() {
        return this.abilities.can('fleet-ops update notification-settings');
    }

    get environments() {
        return [
            { value: '', label: this.intl.t(`${P}.environment-both`) },
            { value: 'production', label: this.intl.t(`${P}.environment-production`) },
            { value: 'sandbox', label: this.intl.t(`${P}.environment-sandbox`) },
        ];
    }

    get tokenTypes() {
        return [
            { value: '', label: this.intl.t(`${P}.token-type-auto`) },
            { value: 'apn', label: this.intl.t(`${P}.token-type-apn`) },
            { value: 'fcm', label: this.intl.t(`${P}.token-type-fcm`) },
        ];
    }

    get editingApp() {
        return this.pushApps.find((app) => app.id === this.editing) ?? null;
    }

    @task *load() {
        try {
            const { push_apps } = yield this.fetch.get('fleet-ops/settings/push-apps');
            this.pushApps = push_apps ?? [];
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action add() {
        this.editing = 'new';
        this.form = blankForm();
        this.error = null;
    }

    @action edit(app) {
        this.editing = app.id;
        this.error = null;
        const { apn, fcm } = app.providers ?? {};
        this.form = {
            name: app.name,
            status: app.status,
            apn: {
                key_id: apn?.key_id ?? '',
                team_id: apn?.team_id ?? '',
                app_bundle_id: apn?.app_bundle_id ?? '',
                private_key_content: '',
                environment: apn?.environment ?? '',
            },
            fcm: { credentials: '', android_channel_id: fcm?.android_channel_id ?? '' },
        };
    }

    @action cancel() {
        this.editing = null;
        this.error = null;
    }

    @action set(path, event) {
        const value = event?.target ? event.target.value : event;
        const [group, field] = path.split('.');
        this.form = field ? { ...this.form, [group]: { ...this.form[group], [field]: value } } : { ...this.form, [group]: value };
    }

    @action toggleStatus(on) {
        this.form = { ...this.form, status: on ? 'active' : 'inactive' };
    }

    @task *save() {
        this.error = null;
        const isNew = this.editing === 'new';
        try {
            const { push_app } = isNew ? yield this.fetch.post('fleet-ops/settings/push-apps', this.form) : yield this.fetch.put(`fleet-ops/settings/push-apps/${this.editing}`, this.form);
            this.pushApps = isNew ? [...this.pushApps, push_app] : this.pushApps.map((app) => (app.id === push_app.id ? push_app : app));
            this.editing = null;
            this.notifications.success(this.intl.t(isNew ? `${P}.created` : `${P}.saved`, { name: push_app.name }));
        } catch (error) {
            this.error = error?.message ?? String(error);
        }
    }

    @action remove(app) {
        this.modalsManager.confirm({
            title: this.intl.t(`${P}.delete-title`, { name: app.name }),
            body: this.intl.t(`${P}.delete-body`, { count: app.devices }),
            acceptButtonText: this.intl.t(`${P}.delete`),
            acceptButtonScheme: 'danger',
            onConfirm: async () => {
                try {
                    await this.fetch.delete(`fleet-ops/settings/push-apps/${app.id}`);
                    this.pushApps = this.pushApps.filter((item) => item.id !== app.id);
                    this.notifications.success(this.intl.t(`${P}.deleted`, { name: app.name }));
                } catch (error) {
                    this.notifications.serverError(error);
                }
            },
        });
    }

    @action openTest(app) {
        this.testing = this.testing === app.id ? null : app.id;
        this.testResult = null;
        this.test = { token: '', push_provider: '', environment: '' };
    }

    @action setTest(field, event) {
        this.test = { ...this.test, [field]: event?.target ? event.target.value : event };
        if (this.test.push_provider === 'fcm') {
            this.test = { ...this.test, environment: '' };
        }
    }

    @task *sendTest(app) {
        this.testResult = null;
        try {
            const result = yield this.fetch.post(`fleet-ops/settings/push-apps/${app.id}/test`, this.test);
            this.testResult = result.sent
                ? { ok: true, text: this.intl.t(`${P}.test-sent`) }
                : { ok: false, text: this.intl.t(`${P}.test-failed`, { reason: result.reason ?? result.status }) };
        } catch (error) {
            this.testResult = { ok: false, text: error?.message ?? String(error) };
        }
    }
}
