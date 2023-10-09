import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { computed } from '@ember/object';

export default class WidgetLiveOrderMapComponent extends Component {
    @tracked routes = [];
    @tracked isLoading = false;
    @computed('args.title') get title() {
        return this.args.title || 'Live Orders';
    }
}
