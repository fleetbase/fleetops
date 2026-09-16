import Component from '@glimmer/component';
import { action, set } from '@ember/object';

export default class ModalsRadarSendPinComponent extends Component {
    @action choose(via) {
        set(this.args.options, 'via', via);
    }
}
