import Component from '@glimmer/component';
import { action } from '@ember/object';
import { htmlSafe } from '@ember/template';

export default class RadarAgendaEntryComponent extends Component {
    get leftStyle() {
        return htmlSafe(`left: ${Math.max(0, Math.min(96, Number(this.args.entry?.pct) || 0))}%`);
    }

    @action open() {
        const entry = this.args.entry;
        if (entry?.rule === 'shift_handover' && this.args.onHandover) {
            return this.args.onHandover(entry.key);
        }

        return this.args.onOpen?.(entry);
    }

    @action dragStart(event) {
        if (!this.args.draggable) {
            return;
        }
        event.dataTransfer?.setData('text/radar-key', this.args.entry.key);
        event.dataTransfer.effectAllowed = 'move';
    }
}
