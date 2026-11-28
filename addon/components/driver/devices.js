import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

/**
 * The phones a driver uses the Navigator app on: what each one is, whether the
 * driver is signed in on it, and a way to send them a notification by hand.
 */
export default class DriverDevicesComponent extends Component {
    @service fetch;
    @service notifications;
    @service intl;

    @tracked devices = [];
    @tracked loaded = false;
    @tracked composing = false;
    @tracked target = null;
    @tracked title = '';
    @tracked message = '';

    constructor() {
        super(...arguments);
        this.load.perform();
    }

    get driverId() {
        return this.args.resource?.id;
    }

    get rows() {
        return this.devices.map((device) => {
            const what = [device.manufacturer, device.model].filter(Boolean).join(' ');
            return {
                ...device,
                heading: device.name || what || (device.platform === 'ios' ? 'iPhone' : device.platform === 'android' ? 'Android phone' : this.intl.t('driver-devices.unknown-device')),
                detail: [device.name ? what : null, device.os_version, device.app_version ? this.intl.t('driver-devices.app-version', { version: [device.app_version, device.app_build].filter(Boolean).join(' · ') }) : null]
                    .filter(Boolean)
                    .join(' · '),
                statusLabel: this.intl.t(device.status === 'invalid' ? 'driver-devices.status-invalid' : device.signed_in ? 'driver-devices.status-signed-in' : 'driver-devices.status-signed-out'),
                statusColor: device.status === 'invalid' ? 'danger' : device.signed_in ? 'success' : 'info',
                isDevelopment: ['development', 'sandbox', 'debug'].includes(device.environment),
                isTarget: this.composing && this.target === device.id,
            };
        });
    }

    get canPushAny() {
        return this.devices.some((device) => device.can_push);
    }

    get cannotSend() {
        return !this.title.trim() || !this.message.trim();
    }

    @task *load() {
        if (!this.driverId) {
            return;
        }
        try {
            const { devices } = yield this.fetch.get(`drivers/${this.driverId}/devices`, {}, { namespace: 'fleet-ops/int/v1' });
            this.devices = devices ?? [];
        } catch (error) {
            this.devices = [];
        }
        this.loaded = true;
    }

    @action compose(device) {
        this.composing = true;
        this.target = device?.id ?? null;
    }

    @action cancel() {
        this.composing = false;
        this.target = null;
        this.title = '';
        this.message = '';
    }

    @task *send() {
        try {
            const result = yield this.fetch.post(
                `drivers/${this.driverId}/send-notification`,
                { title: this.title, message: this.message, device: this.target },
                { namespace: 'fleet-ops/int/v1' }
            );
            this.devices = result.devices ?? this.devices;
            if (result.failures?.length) {
                this.notifications.warning(this.intl.t('driver-devices.sent-partly', { reason: result.failures.join(', ') }));
            } else {
                this.notifications.success(this.intl.t('driver-devices.sent', { count: result.sent_to }));
            }
            this.cancel();
        } catch (error) {
            this.notifications.serverError(error);
            this.load.perform();
        }
    }
}
