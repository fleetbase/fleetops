import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { task } from 'ember-concurrency';
import { registerDestructor } from '@ember/destroyable';
import { action } from '@ember/object';

export default class TelematicTelemetryStatusComponent extends Component {
    @service fetch;
    @tracked diagnostics;
    @tracked webhookUrl;
    @tracked error;
    timer;

    constructor() {
        super(...arguments);
        if (this.supported) this.monitor.perform();
        registerDestructor(this, () => clearTimeout(this.timer));
    }

    @action resourceChanged() {
        this.monitor.cancelAll();
        this.configure.cancelAll();
        clearTimeout(this.timer);
        this.webhookUrl = null;
        this.diagnostics = null;
        this.error = null;
        if (this.supported) this.monitor.perform();
    }

    get provider() {
        return this.args.provider ?? this.args.resource?.provider_descriptor;
    }

    get supported() {
        return Boolean(this.id && this.provider?.metadata?.telemetry?.durable_ingestion);
    }

    get supportsSecureWebhooks() {
        return this.provider?.metadata?.telemetry?.secure_webhooks === true;
    }

    get registrationInstructions() {
        return this.provider?.metadata?.telemetry?.registration_instructions;
    }

    get provisional() {
        return this.provider?.metadata?.telemetry?.contract_status === 'provisional';
    }

    get id() {
        return this.args.resource?.public_id ?? this.args.resource?.id;
    }

    @task *monitor() {
        while (true) {
            try {
                this.diagnostics = yield this.fetch.get(`telematics/${this.id}/telemetry-diagnostics`);
                this.error = null;
            } catch {
                this.error = 'Unable to load telemetry diagnostics. Check that the telemetry migration is installed.';
            }
            yield new Promise((resolve) => {
                this.timer = setTimeout(resolve, 15000);
            });
        }
    }

    @task *configure(rotate = false) {
        try {
            const result = yield this.fetch.post(`telematics/${this.id}/telemetry-webhook`, { rotate });
            this.webhookUrl = result.url;
            this.error = null;
        } catch {
            this.error = 'Unable to prepare the webhook URL. Verify the public HTTPS API URL and telemetry migration.';
        }
    }

    @action reveal() {
        this.configure.perform(false);
    }

    @action rotate() {
        this.configure.perform(true);
    }

    @action async replay(id) {
        try {
            await this.fetch.post(`telematics/${this.id}/telemetry-deliveries/${id}/replay`);
            this.diagnostics = await this.fetch.get(`telematics/${this.id}/telemetry-diagnostics`);
        } catch {
            this.error = 'Unable to queue this delivery for replay.';
        }
    }
}
