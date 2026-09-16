import Component from '@glimmer/component';
import { inject as service } from '@ember/service';

export default class RadarViewBarComponent extends Component {
    @service intl;

    get tabs() {
        const stats = this.args.stats ?? {};

        return [
            { key: 'open', label: this.intl.t('radar.tabs.open'), count: stats.open ?? 0, hasCount: true },
            { key: 'snoozed', label: this.intl.t('radar.tabs.snoozed'), count: stats.snoozed ?? 0, hasCount: true },
            { key: 'resolved', label: this.intl.t('radar.tabs.resolved'), count: null, hasCount: false },
        ];
    }
}
