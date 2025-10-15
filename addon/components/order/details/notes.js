import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class OrderDetailsNotesComponent extends Component {
    @service intl;
    @service notifications;
    @tracked isEditing = false;

    /* eslint-disable ember/no-side-effects */
    get actionButtons() {
        return [
            {
                type: 'default',
                text: 'Edit',
                icon: 'pencil',
                iconPrefix: 'fas',
                permission: 'fleet-ops update order',
                disabled: this.isEditing === true,
                onClick: () => {
                    this.isEditing = true;
                },
            },
        ];
    }

    @task *save() {
        try {
            yield this.args.resource.persistProperty('notes', this.args.resource.notes);
            this.notifications.success(this.intl.t('fleet-ops.operations.orders.index.view.order-notes-updated'));
            this.isEditing = false;
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action stopEditing() {
        this.isEditing = false;
    }
}
