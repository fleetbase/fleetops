import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import copyToClipboard from '@fleetbase/ember-core/utils/copy-to-clipboard';

/**
 * The public links minted for one inspection form.
 *
 * Generating a link used to leave nothing behind but a toast: the URL went to
 * the clipboard and, once that clipboard was overwritten, there was no way to
 * find out what had been handed out, to whom, or whether it still worked. So
 * every link is listed — who and what it was for, when it was made, whether it
 * has been opened, and whether it is still live — with the link itself there
 * to copy again and a way to take it out of use.
 *
 * It reloads whenever a link is generated anywhere in the console, by
 * watching the form actions service, so a list on the details panel stays
 * current while the generate modal is used on top of it.
 */
export default class InspectionLinkListComponent extends Component {
    @service fetch;
    @service notifications;
    @service intl;
    @service inspectionFormActions;

    @tracked links = [];
    @tracked error = null;

    /** Which link is being revoked, so only its own button spins. */
    @tracked revokingId = null;

    constructor() {
        super(...arguments);
        this.load.perform();
    }

    get formId() {
        const form = this.args.form;
        return form?.id ?? form?.public_id ?? form ?? null;
    }

    get hasLinks() {
        return this.links.length > 0;
    }

    /** The absolute URL for a link, which the server returns only as a path. */
    urlFor(link) {
        return link?.path ? `${window.location.origin}${link.path}` : null;
    }

    @task({ restartable: true }) *load() {
        this.error = null;

        if (!this.formId) {
            this.links = [];
            return;
        }

        try {
            const response = yield this.fetch.get(`inspection-forms/${this.formId}/links`);
            this.links = (response?.links ?? []).map((link) => ({ ...link, url: this.urlFor(link) }));
        } catch (error) {
            this.error = error?.payload?.error ?? error?.message ?? this.intl.t('inspection.link.load-failed');
        }
    }

    /** Reload whenever the caller says it has minted one. */
    @action reload() {
        return this.load.perform();
    }

    @action copy(link) {
        if (!link.url) {
            return;
        }

        copyToClipboard(link.url);
        this.notifications.success(this.intl.t('inspection.link.copied'));
    }

    @task({ drop: true }) *revoke(link) {
        this.revokingId = link.id;

        try {
            yield this.fetch.delete(`inspection-forms/${this.formId}/links/${link.id}`);
            this.notifications.success(this.intl.t('inspection.link.revoked'));
            yield this.load.perform();
        } catch (error) {
            this.notifications.serverError(error);
        } finally {
            this.revokingId = null;
        }
    }
}
