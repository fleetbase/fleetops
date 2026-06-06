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
            return {
                status: 'success',
                label: 'Verified',
            };
        }

        if (result === 'failed') {
            return {
                status: 'danger',
                label: 'Failed',
            };
        }

        return null;
    }

    get lastSyncStatus() {
        const result = this.args.resource?.meta?.last_sync_result;

        if (result === 'success') {
            return {
                status: 'success',
                label: 'Synced',
            };
        }

        if (result === 'failed') {
            return {
                status: 'danger',
                label: 'Failed',
            };
        }

        return null;
    }

    get healthCards() {
        const resource = this.args.resource;

        return [
            {
                icon: 'plug',
                label: 'Connection test',
                value: resource?.meta?.last_test_result ?? 'Not tested',
                detail: resource?.meta?.last_connection_test,
                status: this.lastTestStatus?.status,
                statusLabel: this.lastTestStatus?.label,
            },
            {
                icon: 'satellite-dish',
                label: 'Device sync',
                value: resource?.meta?.last_sync_result ?? 'Not synced',
                detail: resource?.meta?.last_sync_completed_at,
                status: this.lastSyncStatus?.status,
                statusLabel: this.lastSyncStatus?.label,
            },
            {
                icon: 'microchip',
                label: 'Devices synced',
                value: resource?.meta?.last_sync_total ?? 'None',
                detail: resource?.meta?.last_sync_job_id,
                status: resource?.meta?.last_sync_total ? 'success' : null,
                statusLabel: resource?.meta?.last_sync_total ? 'Available' : null,
            },
        ];
    }

    get attentionItems() {
        const resource = this.args.resource;
        const items = [];

        if (resource?.meta?.last_error) {
            items.push({
                icon: 'triangle-exclamation',
                title: 'Connection issue',
                description: resource.meta.last_error,
                status: 'warning',
            });
        }

        if (resource?.meta?.last_sync_error) {
            items.push({
                icon: 'circle-exclamation',
                title: 'Sync issue',
                description: resource.meta.last_sync_error,
                status: 'warning',
            });
        }

        if (resource?.meta?.unattached_devices_count > 0) {
            items.push({
                icon: 'truck',
                title: 'Devices need vehicles',
                description: `${resource.meta.unattached_devices_count} synced devices are waiting to be attached.`,
                status: 'warning',
            });
        }

        return items;
    }

    get hardwareFields() {
        const resource = this.args.resource;

        return [
            { label: 'Model', value: resource?.model },
            { label: 'Serial Number', value: resource?.serial_number },
            { label: 'Firmware Version', value: resource?.firmware_version },
            { label: 'IMEI', value: resource?.imei },
            { label: 'ICCID', value: resource?.iccid },
            { label: 'IMSI', value: resource?.imsi },
            { label: 'MSISDN', value: resource?.msisdn },
            { label: 'Signal Strength', value: resource?.signal_strength },
        ];
    }
}
