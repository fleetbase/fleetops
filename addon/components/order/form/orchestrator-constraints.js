import Component from '@glimmer/component';
import { action } from '@ember/object';

export default class OrderFormOrchestratorConstraintsComponent extends Component {
    get _timeWindowReferenceDate() {
        const raw = this.args.resource.scheduled_at ?? this.args.resource.created_at ?? new Date();
        return raw instanceof Date ? raw : new Date(raw);
    }

    @action setTimeWindow(field, value) {
        if (!value) {
            this.args.resource[field] = null;
            return;
        }

        const picked = value instanceof Date ? value : new Date(value);
        if (isNaN(picked.getTime())) {
            this.args.resource[field] = value;
            return;
        }

        const ref = this._timeWindowReferenceDate;
        const isEpochDate = picked.getUTCFullYear() === 1970 && picked.getUTCMonth() === 0 && picked.getUTCDate() === 1;

        if (isEpochDate) {
            const merged = new Date(ref);
            merged.setHours(picked.getHours(), picked.getMinutes(), picked.getSeconds(), 0);
            this.args.resource[field] = merged;
        } else {
            this.args.resource[field] = picked;
        }
    }
}
