import Component from '@glimmer/component';
import { action } from '@ember/object';

export default class OrderScheduleCardComponent extends Component {
    @action setupComponent() {
        const { order } = this.args;

        order?.loadDriver();
        order?.loadPayload();
    }

    @action onTitleClick(order) {
        if (typeof this.args.onTitleClick === 'function') {
            this.args.onTitleClick(order);
        }
    }
}
