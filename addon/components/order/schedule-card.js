import Component from '@glimmer/component';
import { action } from '@ember/object';

export default class OrderScheduleCardComponent extends Component {
    constructor(owner, { order }) {
        super(...arguments);

        if (order && typeof order.loadDriver === 'function') {
            order.loadDriver();
        }

        if (order && typeof order.loadPayload === 'function') {
            order.loadPayload();
        }
    }

    @action onTitleClick(order) {
        const { onTitleClick } = this.args;

        if (typeof onTitleClick === 'function') {
            onTitleClick(order);
        }
    }
}
