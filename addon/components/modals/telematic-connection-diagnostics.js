import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import copyToClipboard from '@fleetbase/ember-core/utils/copy-to-clipboard';

const SENSITIVE_METADATA_KEYS = ['password', 'secret', 'session', 'token'];

export default class ModalsTelematicConnectionDiagnosticsComponent extends Component {
    @service fetch;
    @service modalsManager;
    @service notifications;

    @tracked result;
    @tracked startedAt;
    @tracked completedAt;

    constructor(owner, { options = {} }) {
        super(...arguments);
        this.options = options;
        this.setupOptions();
        this.runTest.perform();
    }

    get telematic() {
        return this.options.telematic;
    }

    get providerLabel() {
        return this.telematic?.provider_descriptor?.label ?? this.telematic?.provider ?? 'Provider';
    }

    get providerKey() {
        return this.telematic?.provider;
    }

    get connectionState() {
        if (this.runTest.isRunning) {
            return 'testing';
        }

        if (!this.result) {
            return 'idle';
        }

        return this.result.success ? 'success' : 'failed';
    }

    get connectionStateTitle() {
        if (this.connectionState === 'testing') {
            return 'Testing provider connection...';
        }

        if (this.connectionState === 'success') {
            return 'Connection verified';
        }

        if (this.connectionState === 'failed') {
            return 'Connection failed';
        }

        return 'Ready to test';
    }

    get connectionStateMessage() {
        if (this.connectionState === 'testing') {
            return 'Waiting for the provider to accept the saved credentials.';
        }

        if (this.connectionState === 'idle') {
            return 'Run a connection test to verify the saved provider credentials.';
        }

        if (this.isDuplicateConnectionMessage) {
            return null;
        }

        return this.result?.message;
    }

    get isDuplicateConnectionMessage() {
        const message = this.result?.message;

        if (!message) {
            return false;
        }

        return ['connection successful', 'connection verified'].includes(message.trim().toLowerCase());
    }

    get connectionMetadataEntries() {
        const metadata = this.result?.metadata ?? {};

        return Object.entries(metadata)
            .filter(([key, value]) => {
                if (value === null || value === undefined || value === '') {
                    return false;
                }

                return !this.isSensitiveMetadataKey(key);
            })
            .map(([key, value]) => ({
                label: this.formatMetadataLabel(key),
                value: this.formatDiagnosticValue(value),
            }));
    }

    get hasConnectionMetadata() {
        return this.connectionMetadataEntries.length > 0;
    }

    get connectionDiagnosticEntries() {
        const entries = [];
        const startedAt = this.startedAt;
        const completedAt = this.completedAt;

        entries.push({
            time: this.formatDiagnosticTime(startedAt),
            tone: 'muted',
            text: `Provider selected: ${this.providerLabel}${this.providerKey ? ` (${this.providerKey})` : ''}`,
        });

        entries.push({
            time: this.formatDiagnosticTime(startedAt),
            tone: 'info',
            text: 'Sending saved provider connection test request',
        });

        if (this.connectionState === 'testing') {
            entries.push({
                time: this.formatDiagnosticTime(),
                tone: 'info',
                text: 'Awaiting provider response',
            });
            return entries;
        }

        if (this.connectionState === 'idle') {
            entries.push({
                time: this.formatDiagnosticTime(),
                tone: 'muted',
                text: 'Connection test has not been run yet',
            });
            return entries;
        }

        entries.push({
            time: this.formatDiagnosticTime(completedAt),
            tone: 'info',
            text: 'Provider response received',
        });

        if (this.hasConnectionMetadata) {
            entries.push({
                time: this.formatDiagnosticTime(completedAt),
                tone: 'muted',
                text: `Provider metadata: ${this.connectionMetadataEntries.map((entry) => `${entry.label}=${entry.value}`).join(', ')}`,
            });
        }

        if (this.connectionState === 'success') {
            entries.push({
                time: this.formatDiagnosticTime(completedAt),
                tone: 'success',
                text: 'Connection verified',
            });
            return entries;
        }

        entries.push({
            time: this.formatDiagnosticTime(completedAt),
            tone: 'danger',
            text: `Connection failed${this.result?.message ? `: ${this.formatDiagnosticValue(this.result.message)}` : ''}`,
        });

        return entries;
    }

    get connectionDiagnosticText() {
        return this.connectionDiagnosticEntries.map((entry) => `[${entry.time}] ${entry.text}`).join('\n');
    }

    setupOptions() {
        this.options.title = this.options.title ?? 'Test Connection';
        this.options.acceptButtonText = 'Run Test';
        this.options.acceptButtonIcon = 'plug';
        this.options.declineButtonText = this.options.declineButtonText ?? 'Close';
        this.options.confirm = async (modal) => {
            modal.startLoading();

            try {
                await this.runTest.perform();
            } finally {
                modal.stopLoading();
            }
        };
    }

    @task *runTest() {
        if (!this.telematic?.id) {
            return;
        }

        this.startedAt = new Date();
        this.completedAt = null;
        this.modalsManager.setOption('acceptButtonDisabled', true);
        this.modalsManager.setOption('acceptButtonText', 'Testing...');

        try {
            const result = yield this.fetch.post(`telematics/${this.telematic.id}/test-connection`);
            this.result = result;
            this.completedAt = new Date();
            yield this.options.onTested?.(result);
        } catch (error) {
            this.completedAt = new Date();
            this.result = {
                success: false,
                message: error.message || 'Connection test failed',
            };
        } finally {
            this.modalsManager.setOption('acceptButtonDisabled', false);
            this.modalsManager.setOption('acceptButtonText', this.result ? 'Test Again' : 'Run Test');
        }
    }

    @action
    copyConnectionDiagnostics() {
        copyToClipboard(this.connectionDiagnosticText)
            .then(() => {
                this.notifications.success('Connection diagnostics copied.');
            })
            .catch(() => {
                this.notifications.error('Unable to copy connection diagnostics.');
            });
    }

    formatMetadataLabel(key) {
        return key.replace(/_/g, ' ').replace(/\b\w/g, (char) => char.toUpperCase());
    }

    formatDiagnosticValue(value) {
        const sanitizedValue = this.sanitizeDiagnosticValue(value);

        if (typeof sanitizedValue === 'object') {
            return JSON.stringify(sanitizedValue);
        }

        return String(sanitizedValue);
    }

    sanitizeDiagnosticValue(value, seen = new WeakSet()) {
        if (!value || typeof value !== 'object') {
            return value;
        }

        if (seen.has(value)) {
            return '[circular]';
        }

        seen.add(value);

        if (Array.isArray(value)) {
            return value.map((item) => this.sanitizeDiagnosticValue(item, seen));
        }

        return Object.entries(value).reduce((acc, [key, item]) => {
            if (!this.isSensitiveMetadataKey(key)) {
                acc[key] = this.sanitizeDiagnosticValue(item, seen);
            }

            return acc;
        }, {});
    }

    formatDiagnosticTime(date = new Date()) {
        if (!(date instanceof Date)) {
            return '--:--:--';
        }

        return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false });
    }

    isSensitiveMetadataKey(key) {
        return SENSITIVE_METADATA_KEYS.some((sensitiveKey) => key.toLowerCase().includes(sensitiveKey));
    }
}
