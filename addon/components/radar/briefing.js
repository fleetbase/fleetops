import Component from '@glimmer/component';
import { action } from '@ember/object';
import { htmlSafe } from '@ember/template';

export const MAX_VISIBLE_DECISIONS = 4;

export default class RadarBriefingComponent extends Component {
    get hasDelta() {
        const delta = this.args.briefing?.score?.delta;

        return delta !== null && delta !== undefined;
    }

    get deltaText() {
        const delta = this.args.briefing?.score?.delta ?? 0;

        return delta > 0 ? `▲ ${delta}` : delta < 0 ? `▼ ${Math.abs(delta)}` : '±0';
    }

    get deltaStatus() {
        const delta = this.args.briefing?.score?.delta ?? 0;

        return delta > 0 ? 'success' : delta < 0 ? 'error' : 'gray';
    }

    get visibleDecisions() {
        return (this.args.briefing?.decisions ?? []).slice(0, MAX_VISIBLE_DECISIONS);
    }

    @action barStyle(score) {
        return htmlSafe(`width: ${Math.max(0, Math.min(100, Number(score) || 0))}%`);
    }

    @action barTone(score) {
        const value = Number(score) || 0;

        return value >= 90 ? 'good' : value >= 70 ? 'warn' : 'bad';
    }

    @action gapText(category) {
        return (category?.gaps ?? []).map((gap) => gap.label).join(' · ');
    }
}
