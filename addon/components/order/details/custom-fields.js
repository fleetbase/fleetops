import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class OrderDetailsCustomFieldsComponent extends Component {
    @service notifications;

    @action async saveCustomFields() {
        if (typeof this.args.onChange === 'function') {
            this.args.onChange(this.args.resource.custom_field_values);
        }

        try {
            await this.args.resource.save();
        } catch (err) {
            this.notifications.serverError(err);
        }
    }
}
