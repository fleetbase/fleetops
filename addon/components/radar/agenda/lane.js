import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { htmlSafe } from '@ember/template';

/**
 * One lane on the time scale. Entries are placed by their percentage
 * offset; overlapping entries are dealt into rows so nothing hides under
 * anything else. Dropping a tray item on the track gives it the time under
 * the cursor.
 */
export default class RadarAgendaLaneComponent extends Component {
    @tracked isOver = false;

    /** Entries dealt into rows: an entry goes to the first row where it does not collide. */
    get rows() {
        const entries = [...(this.args.lane?.entries ?? [])].sort((a, b) => (a.pct ?? 0) - (b.pct ?? 0));
        const rows = [];

        for (const entry of entries) {
            const start = entry.pct ?? 0;
            const end = start + (entry.width_pct ?? 12);
            let row = rows.find((candidates) => candidates.every((placed) => start >= placed.end || end <= placed.start));
            if (!row) {
                row = [];
                rows.push(row);
            }
            row.push({ ...entry, start, end });
        }

        return rows;
    }

    @action barStyle(entry) {
        const left = Math.max(0, Math.min(100, Number(entry.pct) || 0));
        const width = Math.max(2, Math.min(100 - left, Number(entry.width_pct) || 2));

        return htmlSafe(`left: ${left}%; width: ${width}%`);
    }

    @action openShift(entry) {
        if (entry.handover_key) {
            return this.args.onHandover?.(entry.handover_key);
        }

        return this.args.onOpen?.(entry);
    }

    @action dragOver(event) {
        if (!event.dataTransfer?.types?.includes('text/radar-key')) {
            return;
        }
        event.preventDefault();
        this.isOver = true;
    }

    @action dragLeave() {
        this.isOver = false;
    }

    @action drop(event) {
        this.isOver = false;
        const key = event.dataTransfer?.getData('text/radar-key');
        if (!key) {
            return;
        }
        event.preventDefault();

        const track = event.currentTarget;
        const rect = track.getBoundingClientRect();
        const pct = rect.width ? Math.max(0, Math.min(1, (event.clientX - rect.left) / rect.width)) : 0;
        const window = this.args.window ?? {};
        const start = new Date(window.start_at);
        const hours = Number(window.hours) || 24;
        const at = new Date(start.getTime() + pct * hours * 3600 * 1000);
        at.setMinutes(Math.round(at.getMinutes() / 15) * 15, 0, 0);

        this.args.onDrop?.(key, at.toISOString(), this.args.lane?.key);
    }
}
