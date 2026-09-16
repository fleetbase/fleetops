import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { htmlSafe } from '@ember/template';

export const AGENDA_LANES = ['shifts', 'maintenance', 'expiries', 'notices'];
export const TRAY_LIMIT = 8;

export default class RadarAgendaComponent extends Component {
    @service intl;

    get lanes() {
        const lanes = this.args.agenda?.lanes ?? {};

        return AGENDA_LANES.map((key) => ({ key, label: this.intl.t(`radar.agenda.lanes.${key}`), entries: lanes[key] ?? [] }));
    }

    get trayItems() {
        return (this.args.agenda?.anytime ?? []).slice(0, TRAY_LIMIT);
    }

    @action leftStyle(pct) {
        return htmlSafe(`left: ${Math.max(0, Math.min(100, Number(pct) || 0))}%`);
    }

    @action print() {
        window.print();
    }
}
