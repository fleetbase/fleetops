import Component from '@glimmer/component';

export default class TelematicDetailsComponent extends Component {
    get webhookUrl() {
        const url = this.args.resource?.provider_descriptor?.webhook_url;
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

    get lastTestStatus() {
        const result = this.args.resource?.meta?.last_test_result;

        if (result === 'success') {
            return 'success';
        }

        if (result === 'failed') {
            return 'danger';
        }

        return 'default';
    }

    get lastSyncStatus() {
        const result = this.args.resource?.meta?.last_sync_result;

        if (result === 'success') {
            return 'success';
        }

        if (result === 'failed') {
            return 'danger';
        }

        return 'default';
    }
}
