import Component from '@glimmer/component';
import { action } from '@ember/object';

export default class OrderFormMetadataComponent extends Component {
    @action see() {
        console.log('[order]', this.args.resource);
        console.log('[meta]', this.args.resource.meta);
    }
}
