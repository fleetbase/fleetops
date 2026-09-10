import ResourceActionService from '@fleetbase/ember-core/services/resource-action';
import { action, set } from '@ember/object';
import { inject as service } from '@ember/service';
import copyToClipboard from '@fleetbase/ember-core/utils/copy-to-clipboard';
import { normalizeFieldGroups, serializeFieldGroups } from '../utils/inspection-form-structure';

export default class InspectionFormActionsService extends ResourceActionService {
    @service fetch;
    @service notifications;

    constructor() {
        super(...arguments);
        this.initialize('inspection-form', {
            defaultAttributes: {
                type: 'dvir',
                status: 'draft',
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

    /**
     * A form's structure — its field groups and their fields.
     *
     * The `inspection-form` model belongs to `@fleetbase/fleetops-data` and
     * declares no attribute for the structure, so Ember Data drops it on the
     * way in and on the way out. Both directions go through the internal
     * endpoint directly instead: a read carries `field_groups` beside a flat
     * `fields` list, and a write posts the whole thing back under
     * `inspection_form.field_groups`, which is the key
     * `InspectionFormController::syncStructureFromRequest()` reads.
     */
    async loadStructure(form) {
        if (!form?.id) {
            return [];
        }

        const response = await this.fetch.get(`inspection-forms/${form.id}`);

        return normalizeFieldGroups(response?.inspection_form ?? response?.inspectionForm ?? response);
    }

    /**
     * Writes the whole structure. The builder always posts every group and
     * every field, so the server prunes what the post no longer lists — a
     * field the post dropped is a field the author deleted.
     */
    async saveStructure(form, groups) {
        if (!form?.id || !Array.isArray(groups) || groups.length === 0) {
            return null;
        }

        return this.fetch.put(`inspection-forms/${form.id}`, {
            inspection_form: { field_groups: serializeFieldGroups(groups) },
        });
    }

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
