import ResourceActionService from '@fleetbase/ember-core/services/resource-action';
import { action, set } from '@ember/object';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import copyToClipboard from '@fleetbase/ember-core/utils/copy-to-clipboard';
import { normalizeFieldGroups, serializeFieldGroups } from '../utils/inspection-form-structure';

/** A link's life when nobody chooses; the server applies the same when left blank. */
const DEFAULT_LINK_TTL_HOURS = 72;

/** A date as a `datetime-local` input reads it: local time, to the minute. */
export function toDatetimeLocal(date) {
    const pad = (n) => String(n).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

/** The default expiry, filled into the form so the dispatcher can see it. */
function defaultLinkExpiry() {
    return toDatetimeLocal(new Date(Date.now() + DEFAULT_LINK_TTL_HOURS * 60 * 60 * 1000));
}

export default class InspectionFormActionsService extends ResourceActionService {
    @service fetch;
    @service notifications;
    @service intl;

    /**
     * When a public link was last generated. Every open link list watches
     * this — the one inside the generate modal and the one on the form's
     * details panel — so a new link appears in both without a reload.
     */
    @tracked linksChangedAt = 0;

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

    /**
     * Say whether a PIN went out, when one was asked to be sent. A delivery
     * that failed is a warning and not an error: the link exists either way,
     * and its PIN is on screen to share by hand.
     */
    notifyPinDelivery(delivery) {
        if (!delivery) {
            this.notifications.success(this.intl.t('inspection.link.generated-toast'));
            return;
        }

        if (delivery.sent) {
            this.notifications.success(this.intl.t(`inspection.link.pin-sent-${delivery.via}`, { to: delivery.to }));
            return;
        }

        this.notifications.warning(this.intl.t('inspection.link.pin-not-sent', { reason: delivery.error }));
    }

    @action generateLink(form) {
        if (form.status !== 'published') {
            this.notifications.warning(this.intl.t('inspection.link.publish-first'));
            return;
        }

        // Who the link is for, and the driver and vehicle being inspected, are
        // each optional: anyone in the organisation may complete an inspection.
        const formState = {
            assignee: null,
            driver: null,
            vehicle: null,
            expires_at: defaultLinkExpiry(),
            pin_delivery: 'none',
            generated: null,
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
                        assignee: formState.assignee?.id,
                        driver: formState.driver?.id,
                        vehicle: formState.vehicle?.id,
                        // The input holds local time with no zone; sent as it was,
                        // the server read it as its own zone and the link expired
                        // hours early or late. Sent as an instant, it means what
                        // the dispatcher picked.
                        expires_at: formState.expires_at ? new Date(formState.expires_at).toISOString() : null,
                        single_use: true,
                        pin_delivery: formState.pin_delivery,
                    });
                    const link = response?.link;
                    const url = link?.path ? `${window.location.origin}${link.path}` : null;

                    // Shown in the modal until it closes: the link, and the PIN to
                    // share with it by some other way.
                    set(formState, 'generated', { url, pin: link?.pin ?? null });

                    // Every open link list watches this and reloads, so the link
                    // that was just minted appears to be read, copied again or
                    // revoked — rather than living only in the clipboard.
                    this.linksChangedAt = Date.now();

                    if (url) {
                        await copyToClipboard(url);
                    }

                    this.notifyPinDelivery(response?.pin_delivery);

                    modal.stopLoading();
                } catch (error) {
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
        });
    }
}
