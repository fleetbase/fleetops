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
        if (this.args.resource?.status === 'synchronizing') {
            return {
                status: 'info',
                label: 'Syncing',
            };
        }

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

    get connectionTestValue() {
        return this.lastTestStatus?.label ?? 'Not tested';
    }

    get deviceSyncValue() {
        if (this.args.resource?.status === 'synchronizing') {
            return 'Syncing provider devices';
        }

        if (this.args.resource?.meta?.last_sync_result === 'success') {
            return 'Synced';
        }

        if (this.args.resource?.meta?.last_sync_result === 'failed') {
            return 'Failed';
        }

        return 'Not synced';
    }

    get deviceSyncDetail() {
        if (this.args.resource?.status === 'synchronizing') {
            return this.args.resource?.meta?.last_sync_started_at;
        }

        return this.args.resource?.meta?.last_sync_completed_at;
    }

    get connectionTestAccentClass() {
        if (this.lastTestStatus?.status === 'danger') {
            return 'fleetops-connectivity-kpi-accent-rose';
        }

        if (this.lastTestStatus?.status === 'success') {
            return 'fleetops-connectivity-kpi-accent-green';
        }

        return 'fleetops-connectivity-kpi-accent-blue';
    }

    get deviceSyncAccentClass() {
        if (this.lastSyncStatus?.status === 'danger') {
            return 'fleetops-connectivity-kpi-accent-rose';
        }

        if (this.lastSyncStatus?.status === 'success') {
            return 'fleetops-connectivity-kpi-accent-green';
        }

        if (this.args.resource?.status === 'synchronizing') {
            return 'fleetops-connectivity-kpi-accent-blue';
        }

        return 'fleetops-connectivity-kpi-accent-amber';
    }

    get devicesSyncedAccentClass() {
        return this.args.resource?.meta?.last_sync_total ? 'fleetops-connectivity-kpi-accent-green' : 'fleetops-connectivity-kpi-accent-blue';
    }

    get healthCards() {
        const resource = this.args.resource;

        return [
            {
                icon: 'plug',
                label: 'Connection test',
                value: this.connectionTestValue,
                help: 'Provider credentials',
                detailLabel: 'Last test',
                detail: resource?.meta?.last_connection_test,
                detailIsDate: true,
                status: this.lastTestStatus?.status,
                statusLabel: this.lastTestStatus?.label,
                accentClass: this.connectionTestAccentClass,
            },
            {
                icon: 'satellite-dish',
                label: 'Device sync',
                value: this.deviceSyncValue,
                help: 'Provider device discovery',
                detailLabel: this.args.resource?.status === 'synchronizing' ? 'Started' : 'Last sync',
                detail: this.deviceSyncDetail,
                detailIsDate: true,
                status: this.lastSyncStatus?.status,
                statusLabel: this.lastSyncStatus?.label,
                accentClass: this.deviceSyncAccentClass,
            },
            {
                icon: 'microchip',
                label: 'Devices synced',
                value: resource?.meta?.last_sync_total ?? 0,
                help: 'Devices from provider',
                detailLabel: 'Sync job',
                detail: resource?.meta?.last_sync_job_id,
                detailIsDate: false,
                status: resource?.meta?.last_sync_total ? 'success' : null,
                statusLabel: resource?.meta?.last_sync_total ? 'Available' : null,
                accentClass: this.devicesSyncedAccentClass,
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
                description: this.userFacingIssueMessage(resource.meta.last_error, 'Connection test failed. Review the provider credentials and try again.'),
                status: 'warning',
            });
        }

        if (resource?.meta?.last_sync_error) {
            items.push({
                icon: 'circle-exclamation',
                title: 'Sync issue',
                description: this.userFacingIssueMessage(resource.meta.last_sync_error, 'Device sync failed. Review the provider connection and server logs, then try again.'),
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

    userFacingIssueMessage(message, fallback) {
        if (!message || this.isSensitiveIssueMessage(message)) {
            return fallback;
        }

        return String(message);
    }

    isSensitiveIssueMessage(message) {
        const value = String(message).toLowerCase();

        return ['sqlstate', 'insert into', 'update `', 'select ', 'schema', 'stack trace', 'connection:', 'pdoexception'].some((fragment) => value.includes(fragment));
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
