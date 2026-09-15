import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { task } from 'ember-concurrency';
import { registerDestructor } from '@ember/destroyable';
import { action } from '@ember/object';

export default class AfaqyIntegrationStatusComponent extends Component {
    @service fetch;
    @tracked diagnostics;
    @tracked webhookUrl;
    @tracked error;
    timer;

    constructor() {
        super(...arguments);
        this.monitor.perform();
        registerDestructor(this, () => clearTimeout(this.timer));
    }

    @action resourceChanged() {
        this.monitor.cancelAll();
        clearTimeout(this.timer);
        this.webhookUrl = null;
        this.monitor.perform();
    }

    get id() {
        return this.args.resource?.public_id ?? this.args.resource?.id;
    }

    @task *monitor() {
        while (true) {
            try {
                this.diagnostics = yield this.fetch.get(`telematics/${this.id}/afaqy-diagnostics`);
                this.error = null;
            } catch {
                this.error = 'Unable to load AFAQY diagnostics. Check that the telemetry migration is installed.';
            }
            yield new Promise((resolve) => {
                this.timer = setTimeout(resolve, 15000);
            });
        }
    }

    @task *configure(rotate = false) {
        try {
            const result = yield this.fetch.post(`telematics/${this.id}/afaqy-webhook`, { rotate });
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
            await this.fetch.post(`telematics/${this.id}/afaqy-deliveries/${id}/replay`);
            this.diagnostics = await this.fetch.get(`telematics/${this.id}/afaqy-diagnostics`);
        } catch {
            this.error = 'Unable to queue this delivery for replay.';
        }
    }
}
