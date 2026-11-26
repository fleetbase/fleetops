import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

/**
 * The documents the organization asks of a driver, with the driver's own copies
 * to approve or send back. Renders nothing when the organization asks for none.
 */
export default class DriverDocumentsComponent extends Component {
    @service fetch;
    @service notifications;
    @service intl;

    @tracked documents = [];
    @tracked summary = null;
    @tracked rejecting = null;
    @tracked note = '';

    constructor() {
        super(...arguments);
        this.load.perform();
    }

    get driverId() {
        return this.args.resource?.id;
    }

    get rows() {
        return this.documents.map((document) => ({
            ...document,
            statusLabel: this.intl.t(`driver-documents.status-${document.status}`),
            statusColor: { approved: 'success', rejected: 'danger', submitted: 'warning', missing: 'info' }[document.status] ?? 'info',
            canApprove: document.status === 'submitted' || document.status === 'rejected',
            canReject: document.status === 'submitted' || document.status === 'approved',
            isRejecting: this.rejecting === document.key,
        }));
    }

    @task *load() {
        if (!this.driverId) {
            return;
        }
        try {
            const { documents, summary } = yield this.fetch.get(`drivers/${this.driverId}/documents`, {}, { namespace: 'fleet-ops/int/v1' });
            this.documents = documents ?? [];
            this.summary = summary ?? null;
        } catch (error) {
            // Nothing to review if the list cannot be read; the panel stays hidden.
            this.documents = [];
        }
    }

    @task *review(document, decision, note) {
        try {
            const result = yield this.fetch.post(`drivers/${this.driverId}/documents/review`, { document: document.key, decision, note }, { namespace: 'fleet-ops/int/v1' });
            this.documents = result.documents ?? this.documents;
            this.summary = result.summary ?? this.summary;
            this.rejecting = null;
            this.note = '';
            if (result.status && this.args.resource) {
                this.args.resource.status = result.status;
            }
            this.notifications.success(this.intl.t(result.released ? 'driver-documents.released' : decision === 'approved' ? 'driver-documents.approved' : 'driver-documents.rejected'));
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action approve(document) {
        this.review.perform(document, 'approved');
    }

    @action startRejecting(document) {
        this.rejecting = document.key;
        this.note = '';
    }

    @action cancelRejecting() {
        this.rejecting = null;
        this.note = '';
    }

    @action confirmRejecting(document) {
        this.review.perform(document, 'rejected', this.note);
    }

    @action view(document) {
        if (document.file?.url) {
            window.open(document.file.url, '_blank', 'noopener');
        }
    }
}
