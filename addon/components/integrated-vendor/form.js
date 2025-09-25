import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';

export default class IntegratedVendorFormComponent extends Component {
    @tracked showAdvancedOptions = false;

    @action toggleAdvancedOptions() {
        this.showAdvancedOptions = !this.showAdvancedOptions;
    }
}
