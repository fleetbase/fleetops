import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { snoozePayloadFor } from '../../utils/radar';

export default class RadarBulkBarComponent extends Component {
    @service intl;

    /** "2 maint · 1 work order" — the selection by chip. */
    get breakdown() {
        const counts = new Map();
        for (const item of this.args.items ?? []) {
            const label = this.intl.t(`radar.rules.${item.rule}`).toLowerCase();
            counts.set(label, (counts.get(label) ?? 0) + 1);
        }

        return [...counts.entries()].map(([label, count]) => `${count} ${label}`).join(' · ');
    }

    /** Selected inspection links that can still be revoked. */
    get revocableCount() {
        return (this.args.items ?? []).filter((item) => item.actions?.includes('revoke_link')).length;
    }

    get hasSnoozed() {
        return (this.args.items ?? []).some((item) => item.state?.status === 'snoozed');
    }

    @action snooze(preset) {
        return this.args.onAct?.('snooze', { preset, ...snoozePayloadFor(preset) });
    }
}
