import Component from '@glimmer/component';

export default class RadarHandoverCardComponent extends Component {
    get minutesLeft() {
        const minutes = Number(this.args.handover?.shift?.minutes_left ?? 0);
        if (minutes < 60) {
            return `${minutes}m`;
        }
        const hours = Math.floor(minutes / 60);
        const rest = minutes % 60;

        return rest ? `${hours}h ${rest}m` : `${hours}h`;
    }
}
