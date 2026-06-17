import ResourceActionService from '@fleetbase/ember-core/services/resource-action';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';

export default class InspectionFormActionsService extends ResourceActionService {
    @service fetch;
    @service notifications;

    constructor() {
        super(...arguments);
        this.initialize('inspection-form', {
            defaultAttributes: {
                type: 'dvir',
                status: 'draft',
                frequency: 'daily',
                items: [],
                settings: {
                    require_signature: true,
                    create_issue_on_failure: true,
                    create_work_order_on_failure: false,
                },
            },
        });
    }

    transition = {
        view: (form) => this.transitionTo('maintenance.inspection-forms.index.details', form),
        edit: (form) => this.transitionTo('maintenance.inspection-forms.index.edit', form),
        create: () => this.transitionTo('maintenance.inspection-forms.index.new'),
    };

    @action async publish(form) {
        try {
            await this.fetch.post(`inspection-forms/${form.id}/publish`);
            this.notifications.success('Inspection form published.');
            await this.refresh();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action async archive(form) {
        try {
            await this.fetch.post(`inspection-forms/${form.id}/archive`);
            this.notifications.success('Inspection form archived.');
            await this.refresh();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
}
