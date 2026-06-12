import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { formatDistanceStrict, isValid } from 'date-fns';

export default class IssueDetailsComponent extends Component {
    @service hostRouter;
    @service issueActions;

    get resource() {
        return this.args.resource;
    }

    get tags() {
        return Array.isArray(this.resource?.tags) ? this.resource.tags.filter(Boolean) : [];
    }

    get order() {
        return this.resource?.order;
    }

    get files() {
        return this.resource?.files ?? [];
    }

    get hasFiles() {
        return this.files.length > 0;
    }

    get reporterName() {
        return this.resource?.reporter?.name || this.resource?.reporter_name || 'Unknown reporter';
    }

    get reporterInitial() {
        return this.reporterName?.charAt(0)?.toUpperCase() || '?';
    }

    get assigneeName() {
        return this.resource?.assignee?.name || this.resource?.assignee_name || 'Unassigned';
    }

    get isResolved() {
        return ['resolved', 'completed', 'closed'].includes(this.resource?.status);
    }

    get isReopened() {
        return this.resource?.status === 're_opened';
    }

    get resolutionMeta() {
        return this.resource?.meta?.resolution ?? {};
    }

    get reopenHistory() {
        return Array.isArray(this.resource?.meta?.reopen_history) ? this.resource.meta.reopen_history : [];
    }

    get lastReopen() {
        return this.reopenHistory[this.reopenHistory.length - 1];
    }

    get resolutionIcon() {
        if (this.isResolved) {
            return 'circle-check';
        }

        if (this.isReopened) {
            return 'rotate-left';
        }

        return 'hourglass-half';
    }

    get resolutionTitle() {
        if (this.isResolved) {
            return 'Issue closed';
        }

        if (this.isReopened) {
            return 'Issue re-opened';
        }

        return 'Awaiting resolution';
    }

    get resolutionDescription() {
        if (this.isResolved) {
            return this.resolutionMeta.note || 'This issue has been closed and no further action is currently required.';
        }

        if (this.isReopened) {
            return this.lastReopen?.note || 'This issue was previously closed and has been re-opened for follow-up.';
        }

        return 'No resolution details have been recorded yet.';
    }

    get resolutionDate() {
        return this.resolutionMeta.closed_at || this.resource?.resolvedAt || this.resource?.resolved_at;
    }

    get resolutionActor() {
        return this.resolutionMeta.closed_by_name;
    }

    get resolutionNote() {
        return this.resolutionMeta.note;
    }

    get timeToResolve() {
        const createdAt = this.dateFromValue(this.resource?.created_at);
        const resolvedAt = this.dateFromValue(this.resource?.resolved_at);

        if (!createdAt || !resolvedAt) {
            return null;
        }

        return formatDistanceStrict(createdAt, resolvedAt);
    }

    get hasLocation() {
        const location = this.resource?.location;
        return Boolean(location?.latitude || location?.longitude || location?.coordinates);
    }

    dateFromValue(value) {
        if (!value) {
            return null;
        }

        const date = value instanceof Date ? value : new Date(value);

        return isValid(date) ? date : null;
    }

    @action viewOrder() {
        if (this.order) {
            return this.hostRouter.transitionTo('console.fleet-ops.operations.orders.index.details', this.order);
        }
    }

    @action viewVehicle() {
        return this.issueActions.viewVehicle(this.resource);
    }

    @action viewDriver() {
        return this.issueActions.viewDriver(this.resource);
    }
}
