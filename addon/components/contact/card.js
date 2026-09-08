import Component from '@glimmer/component';
import { inject as service } from '@ember/service';

/**
 * Card for contacts and customers. Customers are contacts driven by the customer
 * actions service, so the actions service can be passed in as `@resourceActions`.
 */
export default class ContactCardComponent extends Component {
    @service contactActions;

    get resourceActions() {
        return this.args.resourceActions ?? this.contactActions;
    }
}
