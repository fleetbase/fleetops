import ResourceActionService from '@fleetbase/ember-core/services/resource-action';
import { action, set } from '@ember/object';
import { inject as service } from '@ember/service';
import copyToClipboard from '@fleetbase/ember-core/utils/copy-to-clipboard';

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

    @action generateLink(form) {
        if (form.status !== 'published') {
            this.notifications.warning('Publish this inspection form before generating a public link.');
            return;
        }

        const formState = {
            driver: null,
            vehicle: null,
            expires_at: null,
            generatedUrl: null,
        };

        return this.modalsManager.show('modals/inspection-link', {
            title: 'Generate Inspection Link',
            acceptButtonText: 'Generate Link',
            acceptButtonIcon: 'link',
            declineButtonText: 'Close',
            form,
            formState,
            confirm: async (modal) => {
                modal.startLoading();
                try {
                    const response = await this.fetch.post(`inspection-forms/${form.id}/generate-link`, {
                        driver: formState.driver?.id,
                        vehicle: formState.vehicle?.id,
                        expires_at: formState.expires_at || null,
                        single_use: true,
                    });
                    const path = response?.link?.path;
                    const url = path ? `${window.location.origin}${path}` : null;
                    set(formState, 'generatedUrl', url);

                    if (url) {
                        await copyToClipboard(url);
                        this.notifications.success('Inspection link generated and copied.');
                    } else {
                        this.notifications.success(response?.message ?? 'Inspection link generated.');
                    }

                    modal.stopLoading();
                } catch (error) {
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
        });
    }
}
