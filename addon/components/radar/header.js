import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';

export default class RadarHeaderComponent extends Component {
    @tracked query = this.args.query ?? '';

    @action onInput(event) {
        this.query = event.target.value;
        this.args.onSearch?.(this.query);
    }
}
