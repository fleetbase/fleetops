import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import { copyToClipboard } from '@fleetbase/ember-core/utils/clipboard';

export default class FuelIntegrationFormComponent extends Component {
    @service fetch;
    @service notifications;

    @tracked providers = [];
    @tracked activeStep = 0;
    @tracked connectionTestResult;
    @tracked showDiagnostics = false;

    constructor() {
        super(...arguments);
        this.loadProviders.perform();
    }

    get setupSteps() {
        return [
            { icon: 'gas-pump', label: 'Provider', complete: Boolean(this.selectedProvider) },
            { icon: 'key', label: 'Credentials', complete: this.hasRequiredCredentials },
            { icon: 'plug', label: 'Test', complete: this.connectionTestResult?.success === true },
            { icon: 'clock-rotate-left', label: 'Sync', complete: Boolean(this.syncSettings.window_days) },
            { icon: 'route', label: 'Matching', complete: this.matchingOrder.length > 0 },
        ].map((step, index) => ({
            ...step,
            active: this.activeStep === index,
        }));
    }

    get selectedProvider() {
        return this.providers.find((provider) => provider.key === this.args.resource?.provider);
    }

    get providerCards() {
        return this.providers.map((provider) => ({
            ...provider,
            selected: provider.key === this.args.resource?.provider,
            categoryLabel: provider.category ?? provider.metadata?.category ?? 'Fuel card integration',
        }));
    }

    get credentials() {
        return this.args.resource?.credentials ?? {};
    }

    get syncSettings() {
        return this.args.resource?.sync_settings ?? {};
    }

    get matchingOrder() {
        return this.syncSettings.matching_order ?? [];
    }

    get hasRequiredCredentials() {
        const requiredFields = this.selectedProvider?.required_fields?.filter((field) => field.required) ?? [];

        return requiredFields.every((field) => {
            const value = this.credentials[field.name];
            return value !== null && value !== undefined && String(value).trim() !== '';
        });
    }

    get connectionState() {
        if (this.testConnection.isRunning) {
            return 'testing';
        }

        if (!this.connectionTestResult) {
            return 'idle';
        }

        return this.connectionTestResult.success ? 'success' : 'failed';
    }

    get connectionStateTitle() {
        switch (this.connectionState) {
            case 'testing':
                return 'Testing provider credentials...';
            case 'success':
                return 'Connection verified';
            case 'failed':
                return 'Connection failed';
            default:
                return 'Ready to test';
        }
    }

    get connectionStateMessage() {
        if (this.connectionState === 'idle') {
            return 'Run a live credential test before saving so operators know the integration can import fuel data.';
        }

        if (this.connectionState === 'testing') {
            return 'FleetOps is sending the configured credentials to the fuel integration and waiting for a response.';
        }

        return this.connectionTestResult?.message;
    }

    get connectionMetadataEntries() {
        return Object.entries(this.connectionTestResult?.metadata ?? {}).map(([key, value]) => ({
            label: key.replaceAll('_', ' '),
            value,
        }));
    }

    get diagnosticEntries() {
        const entries = [
            {
                time: new Date().toLocaleTimeString(),
                tone: 'info',
                text: this.selectedProvider ? `Provider selected: ${this.selectedProvider.label}` : 'No provider selected',
            },
        ];

        if (this.connectionTestResult) {
            entries.push({
                time: new Date().toLocaleTimeString(),
                tone: this.connectionTestResult.success ? 'success' : 'danger',
                text: this.connectionTestResult.message ?? 'Connection test returned without a message',
            });
        }

        return entries;
    }

    get diagnosticsText() {
        return this.diagnosticEntries.map((entry) => `[${entry.time}] ${entry.text}`).join('\n');
    }

    @task *loadProviders() {
        try {
            const providers = yield this.fetch.get('fuel-provider-connections/providers');
            this.providers = providers;

            if (!this.args.resource?.provider) {
                const initialProvider = this.args.initialProviderKey ? providers.find((provider) => provider.key === this.args.initialProviderKey) : providers[0];
                if (initialProvider) {
                    this.selectProvider(initialProvider);
                }
            }
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task *testConnection() {
        if (!this.selectedProvider) {
            this.notifications.warning('Choose a fuel integration provider first.');
            return;
        }

        if (!this.hasRequiredCredentials) {
            this.notifications.warning('Enter the required credentials before testing.');
            return;
        }

        try {
            this.connectionTestResult = yield this.fetch.post(`fuel-provider-connections/providers/${this.selectedProvider.key}/test-credentials`, {
                credentials: this.credentials,
                environment: this.args.resource?.environment,
                connection_id: this.args.resource?.id,
            });
        } catch (error) {
            this.connectionTestResult = {
                success: false,
                message: error.message ?? 'Connection test failed.',
                metadata: {},
            };
            this.notifications.serverError(error);
        }
    }

    @action selectProvider(provider) {
        this.args.resource?.setProperties?.({
            provider: provider.key,
            name: this.args.resource?.name || provider.label,
            credentials: (provider.required_fields ?? []).reduce((credentials, field) => {
                credentials[field.name] = this.credentials[field.name] ?? field.default ?? null;
                return credentials;
            }, {}),
        });
        this.connectionTestResult = null;
    }

    @action setCredential(field, event) {
        this.args.resource?.set('credentials', {
            ...this.credentials,
            [field.name]: event.target.value,
        });
        this.connectionTestResult = null;
    }

    @action setSyncSetting(key, eventOrValue) {
        const value = eventOrValue?.target ? (eventOrValue.target.type === 'checkbox' ? eventOrValue.target.checked : eventOrValue.target.value) : eventOrValue;
        this.args.resource?.set('sync_settings', {
            ...this.syncSettings,
            [key]: value,
        });
    }

    @action setEnvironment(event) {
        this.args.resource?.set('environment', event.target.value);
    }

    @action setName(event) {
        this.args.resource?.set('name', event.target.value);
    }

    @action toggleMatchingField(field) {
        const current = this.matchingOrder;
        const next = current.includes(field) ? current.filter((item) => item !== field) : [...current, field];

        this.args.resource?.set('sync_settings', {
            ...this.syncSettings,
            matching_order: next,
        });
    }

    @action goToStep(index) {
        this.activeStep = index;
    }

    @action nextStep() {
        this.activeStep = Math.min(this.activeStep + 1, this.setupSteps.length - 1);
    }

    @action previousStep() {
        this.activeStep = Math.max(this.activeStep - 1, 0);
    }

    @action copyDiagnostics() {
        copyToClipboard(this.diagnosticsText)
            .then(() => this.notifications.success('Connection diagnostics copied.'))
            .catch(() => this.notifications.error('Unable to copy diagnostics.'));
    }
}
