import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import copyToClipboard from '@fleetbase/ember-core/utils/copy-to-clipboard';

export default class FuelIntegrationFormComponent extends Component {
    @service fetch;
    @service notifications;

    @tracked providers = [];
    @tracked activeStep = 0;
    @tracked connectionTestResult;
    @tracked showDiagnostics = false;
    @tracked selectedAt = new Date().toISOString();
    @tracked testedAt;

    constructor() {
        super(...arguments);
        this.loadProviders.perform();
    }

    get isEditing() {
        return this.args.isEditing === true || this.args.resource?.isNew === false;
    }

    get setupSteps() {
        return [
            { key: 'provider', icon: 'gas-pump', label: 'Provider', complete: Boolean(this.selectedProvider) },
            { key: 'credentials', icon: 'key', label: 'Credentials', complete: this.hasRequiredCredentials },
            { key: 'test', icon: 'plug', label: 'Test', complete: this.connectionTestResult?.success === true },
            { key: 'sync', icon: 'clock-rotate-left', label: 'Sync', complete: Boolean(this.syncSettings.window_days) },
            { key: 'matching', icon: 'route', label: 'Matching', complete: this.matchingOrder.length > 0 },
        ]
            .filter((step) => !this.isEditing || step.key !== 'provider')
            .map((step, index) => ({
                ...step,
                active: this.activeStep === index,
                complete: index < this.activeStep && step.complete,
            }));
    }

    get activeStepKey() {
        return this.setupSteps[this.activeStep]?.key;
    }

    get isLastStep() {
        return this.activeStep === this.setupSteps.length - 1;
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

    get environment() {
        return this.args.resource?.environment ?? 'production';
    }

    get baseUrl() {
        return this.credentials.base_url?.trim() || this.selectedProvider?.metadata?.base_urls?.[this.environment];
    }

    get credentialFields() {
        return (this.selectedProvider?.required_fields ?? []).map((field) => ({
            ...field,
            options: field.options?.map((option) => ({
                ...option,
                selected: option.value === (this.credentials[field.name] ?? (field.name === 'auth_type' ? 'bearer_token' : field.default)),
            })),
        }));
    }

    get syncSettings() {
        return this.args.resource?.sync_settings ?? {};
    }

    get matchingOrder() {
        return this.syncSettings.matching_order;
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
            return 'The configured credentials are being checked with the fuel integration provider.';
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
                time: this.selectedAt,
                tone: 'info',
                text: this.selectedProvider ? `Provider selected: ${this.selectedProvider.label}` : 'No provider selected',
            },
        ];

        if (this.connectionTestResult) {
            entries.push({
                time: this.testedAt,
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

            if (!this.isEditing && !this.args.resource?.provider) {
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

        this.connectionTestResult = null;
        try {
            this.connectionTestResult = yield this.fetch.post(
                `fuel-provider-connections/providers/${this.selectedProvider.key}/test-credentials`,
                {
                    credentials: this.credentials,
                    connection_id: this.args.resource?.id,
                    environment: this.environment,
                },
                { rawError: true }
            );
        } catch (error) {
            this.connectionTestResult = {
                success: false,
                message: error.message ?? 'Connection test failed.',
                metadata: error.metadata ?? {},
            };
            this.notifications.serverError(error);
        } finally {
            this.testedAt = new Date().toISOString();
        }
    }

    @action selectProvider(provider) {
        if (this.isEditing) {
            return;
        }

        this.testConnection.cancelAll();
        this.args.resource?.setProperties?.({
            provider: provider.key,
            name: this.args.resource?.name || provider.label,
            credentials: (provider.required_fields ?? []).reduce((credentials, field) => {
                credentials[field.name] = this.credentials[field.name] ?? (field.name === 'auth_type' && this.args.resource?.provider ? 'bearer_token' : field.default) ?? null;
                return credentials;
            }, {}),
        });
        this.selectedAt = new Date().toISOString();
        this.connectionTestResult = null;
    }

    @action setEnvironment(event) {
        this.testConnection.cancelAll();
        this.args.resource?.set('environment', event.target.value);
        this.connectionTestResult = null;
    }

    @action setCredential(field, event) {
        this.testConnection.cancelAll();
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

    @action setName(event) {
        this.args.resource?.set('name', event.target.value);
    }

    @action toggleMatchingField(field) {
        const current = this.matchingOrder;
        const next = current.includes(field) ? current.filter((item) => item !== field) : [...current, field];

        this.setMatchingOrder(next);
    }

    @action setMatchingOrder(next) {
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
