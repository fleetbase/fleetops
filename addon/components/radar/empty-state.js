import Component from '@glimmer/component';

export default class RadarEmptyStateComponent extends Component {
    get kind() {
        if (this.args.isFiltered) {
            return 'filtered';
        }

        return this.args.status ?? 'open';
    }

    get isAllClear() {
        return this.kind === 'open';
    }

    get icon() {
        return { open: 'circle-check', filtered: 'filter', snoozed: 'clock', resolved: 'clipboard-check' }[this.kind] ?? 'inbox';
    }

    get titleKey() {
        return { open: 'radar.empty.title', filtered: 'radar.empty.filtered-title', snoozed: 'radar.empty.snoozed-title', resolved: 'radar.empty.resolved-title' }[this.kind];
    }

    get hintKey() {
        return { filtered: 'radar.empty.filtered-hint', snoozed: 'radar.empty.snoozed-tab-hint', resolved: 'radar.empty.resolved-hint' }[this.kind] ?? 'radar.empty.nothing-snoozed';
    }
}
