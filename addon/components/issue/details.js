import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { formatDistanceStrict, isValid } from 'date-fns';

const COMPLETE_ORDER_STATUSES = ['completed', 'delivered'];
const ACTIVE_ORDER_STATUSES = ['dispatched', 'started', 'in_progress', 'driver_enroute', 'driver_nearby'];

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

    get orderLabel() {
        return this.order?.tracking_number?.tracking_number || this.order?.tracking_number || this.order?.tracking || this.order?.public_id || this.order?.id;
    }

    get orderStops() {
        const payload = this.order?.payload ?? {};
        const stops = [];

        if (payload.pickup) {
            stops.push({ type: 'pickup', place: payload.pickup });
        }

        (payload.waypoints ?? []).forEach((waypoint, index) => {
            stops.push({
                type: waypoint.type ?? 'waypoint',
                place: waypoint.place ?? waypoint,
                index: index + 1,
            });
        });

        if (payload.dropoff) {
            stops.push({ type: 'dropoff', place: payload.dropoff });
        }

        if (payload.return) {
            stops.push({ type: 'return', place: payload.return });
        }

        return stops.filter((stop) => this.addressForPlace(stop.place));
    }

    get orderOrigin() {
        return this.orderStops[0];
    }

    get orderDestination() {
        return this.orderStops[this.orderStops.length - 1];
    }

    get orderOriginAddress() {
        return this.addressForPlace(this.orderOrigin?.place);
    }

    get orderDestinationAddress() {
        return this.addressForPlace(this.orderDestination?.place);
    }

    get orderStopCount() {
        return this.orderStops.length;
    }

    get middleStopCount() {
        return Math.max(this.orderStopCount - 2, 0);
    }

    get isMultiStopOrder() {
        return this.orderStopCount > 2;
    }

    get hasOrderStops() {
        return this.orderStopCount > 0;
    }

    get orderProgressClass() {
        const status = this.order?.status;

        if (COMPLETE_ORDER_STATUSES.includes(status)) {
            return 'is-complete';
        }

        if (ACTIVE_ORDER_STATUSES.includes(status)) {
            return 'is-active';
        }

        return 'is-pending';
    }

    get orderScheduledAt() {
        return this.order?.scheduled_at || this.order?.created_at || this.order?.createdAt;
    }

    get orderDriverLabel() {
        return this.order?.driver_assigned?.name || this.order?.driver_name || this.order?.driver?.name;
    }

    get orderVehicleLabel() {
        return this.order?.vehicle_assigned?.displayName || this.order?.vehicle_assigned?.display_name || this.order?.vehicle_name || this.order?.vehicle?.displayName;
    }

    get hasLocation() {
        const location = this.resource?.location;
        return Boolean(location?.latitude || location?.longitude || location?.coordinates);
    }

    addressForPlace(place) {
        return place?.address ?? place?.address_html ?? place?.street1 ?? place?.name;
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
