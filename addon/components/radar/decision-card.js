import Component from '@glimmer/component';

export default class RadarDecisionCardComponent extends Component {
    get severityStatus() {
        return { critical: 'error', warning: 'warning', info: 'info' }[this.args.decision?.severity] ?? 'gray';
    }
}
