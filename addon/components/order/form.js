import Component from '@glimmer/component';
import { inject as service } from '@ember/service';

export default class OrderFormComponent extends Component {
    @service orderConfigActions;

    constructor() {
        super(...arguments);
        this.orderConfigActions.loadAll.perform();
    }
}
