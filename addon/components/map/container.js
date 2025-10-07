import Component from '@glimmer/component';
import { action } from '@ember/object';

export default class MapContainerComponent extends Component {
    @action didInsert() {
        if (typeof this.args.didInsert === 'function') {
            this.args.didInsert(...arguments);
        }
    }
}
