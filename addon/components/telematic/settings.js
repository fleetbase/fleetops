import Component from '@glimmer/component';
import { action } from '@ember/object';

export default class TelematicSettingsComponent extends Component {
    get provider() {
        return this.args.resource?.provider_descriptor ?? {};
    }

    get credentialFields() {
        return (this.provider.required_fields ?? []).filter((field) => !field.advanced && !field.is_endpoint);
    }

    get advancedCredentialFields() {
        return (this.provider.required_fields ?? []).filter((field) => field.advanced || field.is_endpoint);
    }

    get hasAdvancedCredentialFields() {
        return this.advancedCredentialFields.length > 0;
    }

    get webhookUrl() {
        const url = this.provider.webhook_url;
        const id = this.args.resource?.public_id;

        if (!url || !id) {
            return null;
        }

        const separator = url.includes('?') ? '&' : '?';
        return `${url}${separator}telematic=${id}`;
    }

    get hasWebhookUrl() {
        return Boolean(this.webhookUrl);
    }

    @action setCredential(field, { target: { value } }) {
        const credentials = this.args.resource?.credentials ?? {};

        this.args.resource?.set('credentials', {
            ...credentials,
            [field.name]: value,
        });
    }
}
