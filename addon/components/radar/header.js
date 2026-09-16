import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';

export default class RadarHeaderComponent extends Component {
    @tracked query = this.args.query ?? '';

    get lastSync() {
        const at = this.args.lastLoadedAt;
        if (!at) {
            return '—';
        }

        return new Date(at).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
    }

    @action onInput(event) {
        this.query = event.target.value;
        this.args.onSearch?.(this.query);
    }
}
