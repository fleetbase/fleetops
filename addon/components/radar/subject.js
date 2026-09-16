import Component from '@glimmer/component';
import { initialsOf } from '../../utils/radar';

const ICONS = {
    vehicle: 'truck',
    trailer: 'trailer',
    device: 'satellite-dish',
    part: 'gear',
    fuel_transaction: 'gas-pump',
    inspection_submission: 'clipboard-check',
    inspection_link: 'link',
    schedule: 'calendar-day',
    work_order: 'clipboard-list',
    issue: 'triangle-exclamation',
    shift: 'user-clock',
};

export default class RadarSubjectComponent extends Component {
    get initials() {
        return initialsOf(this.args.subject?.label);
    }

    get icon() {
        return ICONS[this.args.subject?.type] ?? 'circle';
    }
}
