import Component from '@glimmer/component';

export default class CellTelematicStatusComponent extends Component {
    get status() {
        return this.args.row?.status;
    }

    get badgeStatus() {
        switch (this.status) {
            case 'active':
            case 'connected':
                return 'success';
            case 'synchronizing':
                return 'info';
            case 'error':
            case 'degraded':
            case 'disconnected':
                return 'warning';
            default:
                return this.status ?? 'default';
        }
    }

    get label() {
        switch (this.status) {
            case 'active':
            case 'connected':
                return 'Connected';
            case 'synchronizing':
                return 'Syncing';
            case 'initialized':
                return 'Not tested';
            case 'error':
                return 'Needs attention';
            default:
                return this.status ?? 'Unknown';
        }
    }
}
