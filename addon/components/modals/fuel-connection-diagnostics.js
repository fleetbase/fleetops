import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import copyToClipboard from '@fleetbase/ember-core/utils/copy-to-clipboard';

const METADATA_LABELS = { environment: 'Environment', host: 'Host', auth_type: 'Authentication', status: 'HTTP status', total_vehicles: 'Vehicles available' };

export default class FuelConnectionDiagnosticsComponent extends Component {
    @service fetch;
    @service notifications;
    @tracked result;
    @tracked entries = [];
    @tracked refreshWarning;

    constructor() {
        super(...arguments);
        // Keep the result visible until the operator closes the dialog.
        this.args.options.keepOpen = true;
        this.args.options.confirm = () => this.runTest.perform();
    }

    get connection() {
        return this.args.options.connection;
    }
    get environment() {
        return this.connection.environment === 'sandbox' ? 'Sandbox / test account' : 'Production account';
    }
    get modalOptions() {
        return { ...this.args.options, acceptButtonText: this.result ? 'Test Again' : 'Run Test', isLoading: this.runTest.isRunning, acceptButtonDisabled: this.runTest.isRunning };
    }
    get state() {
        return this.runTest.isRunning ? 'testing' : !this.result ? 'ready' : this.result.success ? 'success' : 'failed';
    }
    get title() {
        return { ready: 'Ready to test', testing: 'Testing connection…', success: 'Connection verified', failed: 'Connection failed' }[this.state];
    }
    get message() {
        if (this.state === 'ready') return 'Run a connection test using this integration’s saved credentials. This checks provider access without importing transactions.';
        if (this.state === 'testing') return 'Waiting for the provider to respond…';
        return this.result.message;
    }
    get metadata() {
        return Object.entries(METADATA_LABELS).flatMap(([key, label]) => {
            const value = this.result?.metadata?.[key];
            return ['string', 'number', 'boolean'].includes(typeof value) ? [{ label, value: String(value) }] : [];
        });
    }
    get diagnosticsText() {
        return this.entries.map((entry) => `[${entry.time}] ${entry.text}`).join('\n');
    }
    log(text) {
        this.entries = [...this.entries, { time: new Date().toISOString(), text }];
    }

    @task({ drop: true }) *runTest() {
        this.result = null;
        this.refreshWarning = null;
        this.entries = [];
        this.log(`Testing ${this.connection.name || this.connection.provider} · ${this.environment}`);
        this.log('Sending connection test with saved credentials.');
        try {
            const result = yield this.fetch.post(`fuel-provider-connections/${this.connection.id}/test-connection`, {}, { rawError: true });
            this.result = { ...result, success: result.success === true };
        } catch (error) {
            this.result = { success: false, message: error.message || 'Unable to complete the connection test. Please retry.', metadata: error.metadata ?? {} };
        }
        this.log(this.result.success ? 'Connection verified.' : `Connection failed: ${this.result.message}`);
        for (const entry of this.metadata) this.log(`${entry.label}: ${entry.value}`);
        // Updating the summary must not refresh the router or dismiss the result.
        try {
            yield this.args.options.onTested?.(this.result);
        } catch {
            this.refreshWarning = 'The test result is available, but the integration summary could not be refreshed.';
        }
    }

    @action async copyDiagnostics() {
        try {
            await copyToClipboard(this.diagnosticsText);
            this.notifications.success('Connection diagnostics copied.');
        } catch {
            this.notifications.error('Unable to copy diagnostics.');
        }
    }
}
