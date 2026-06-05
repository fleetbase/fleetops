import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action, get } from '@ember/object';
import { task } from 'ember-concurrency';
import config from 'ember-get-config';
import { buildServiceStopsFromPayload } from '../../utils/route-visualization';

export default class ModalsUpdateOrderActivityComponent extends Component {
    @service fetch;
    @service intl;
    @service notifications;
    @service modalsManager;
    @tracked activityOptions = [];
    @tracked order;
    @tracked selectedActivity;
    @tracked customActivity = {};
    @tracked proofFiles = [];
    @tracked proof;
    @tracked bypassProof = false;

    constructor(owner, { options = {} }) {
        super(...arguments);
        this.order = options.order;
        this.loadActivity.perform();
    }

    get waypoints() {
        const waypoints = this.order?.payload?.waypoints;
        if (typeof waypoints?.toArray === 'function') {
            return waypoints.toArray();
        }

        return Array.isArray(waypoints) ? waypoints : Array.from(waypoints ?? []);
    }

    get serviceStops() {
        return buildServiceStopsFromPayload(this.order?.payload);
    }

    get activeStopId() {
        const activeStop = this.order?.tracker_data?.active_stop;

        return activeStop?.uuid ?? activeStop?.public_id ?? this.order?.payload?.current_waypoint_uuid ?? this.order?.payload?.current_waypoint;
    }

    get isMultipleWaypointOrder() {
        return this.waypoints.length > 0;
    }

    get orderHasStarted() {
        const status = this.order?.status;

        return Boolean(this.order?.started || this.order?.started_at || (status && !['created', 'dispatched'].includes(status)));
    }

    get shouldUseWaypointActivity() {
        return this.isMultipleWaypointOrder && this.orderHasStarted;
    }

    get currentWaypoint() {
        if (!this.shouldUseWaypointActivity) {
            return null;
        }

        const currentWaypointId = this.activeStopId;

        if (currentWaypointId) {
            const stop = this.serviceStops.find(({ place }) => {
                return [place?.id, place?.uuid, place?.public_id, place?.waypoint_public_id].includes(currentWaypointId);
            });

            if (stop?.place) {
                return stop.place;
            }
        }

        return this.serviceStops.find(({ place }) => !place?.complete)?.place ?? this.serviceStops[0]?.place;
    }

    get currentWaypointId() {
        const waypoint = this.currentWaypoint;

        return waypoint?.id ?? waypoint?.public_id ?? waypoint?.uuid;
    }

    get currentWaypointProofSubjectId() {
        const waypoint = this.currentWaypoint;

        return waypoint?.waypoint_public_id ?? waypoint?.id ?? waypoint?.public_id ?? waypoint?.uuid;
    }

    get selectedActivityRecord() {
        if (this.selectedActivity === 'custom') {
            return this.customActivity;
        }

        return this.activityOptions[this.selectedActivity];
    }

    get selectedActivityRequiresPod() {
        return Boolean(this.selectedActivityRecord?.require_pod);
    }

    get acceptedProofFileTypes() {
        return ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    }

    get hasUploadedProof() {
        return Boolean(this.proof?.id || this.proof?.public_id);
    }

    get uploadedProofId() {
        return this.proof?.id ?? this.proof?.public_id;
    }

    @task *loadActivity() {
        if (!this.order?.id) {
            return [];
        }

        try {
            const params = this.shouldUseWaypointActivity && this.currentWaypointId ? { waypoint: this.currentWaypointId } : {};
            const activityOptions = yield this.fetch.get(`orders/next-activity/${this.order.id}`, params);
            this.activityOptions = activityOptions;
            return activityOptions;
        } catch (err) {
            this.notifications.serverError(err);
        }
    }

    @task *submitActivity(modal) {
        modal.startLoading();

        const activity = this.selectedActivityRecord;
        if (this.selectedActivity === 'custom' && !activity.status && !activity.details && !activity.code) {
            modal.stopLoading();
            return this.notifications.warning(this.intl.t('fleet-ops.operations.orders.index.view.invalid-warning'));
        }

        if (this.selectedActivityRequiresPod && this.uploadProofFile.isRunning) {
            modal.stopLoading();
            return this.notifications.warning('Photo proof of delivery is still uploading.');
        }

        if (this.selectedActivityRequiresPod && !this.hasUploadedProof && !this.bypassProof) {
            modal.stopLoading();
            return this.notifications.warning('Upload photo proof of delivery or choose to bypass proof of delivery.');
        }

        try {
            yield this.fetch.patch(`orders/update-activity/${this.order.id}`, {
                activity,
                proof: this.hasUploadedProof ? this.uploadedProofId : null,
                bypass_proof: this.selectedActivityRequiresPod && this.bypassProof,
            });

            if (typeof this.order?.reload === 'function') {
                yield this.order.reload();
            }

            if (typeof this.order?.loadTrackerData === 'function') {
                if (typeof this.order.set === 'function') {
                    this.order.set('tracker_data', null);
                }

                yield this.order.loadTrackerData();
            }

            this.modalsManager.setOption('activityCreated', activity);
            this.modalsManager.setOption('proofCreated', this.hasUploadedProof ? this.proof : null);
            this.notifications.success(`Order activity has been updated to ${activity.status}`);
            modal.done();
        } catch (error) {
            this.notifications.serverError(error);
        } finally {
            modal.stopLoading();
        }
    }

    @task *capturePhotoProof() {
        const [proofFile] = this.proofFiles;
        if (!proofFile) {
            return null;
        }

        const headers = this.fetch.getHeaders();
        delete headers['Content-Type'];

        const subjectId = this.currentWaypointProofSubjectId;
        const subjectPath = subjectId ? `/${subjectId}` : '';
        const url = [get(config, 'API.host'), get(config, 'API.namespace'), `orders/${this.order.id}/capture-photo${subjectPath}`].filter(Boolean).join('/');

        const response = yield proofFile.upload(url, {
            data: {
                remarks: 'Verified by Photo',
            },
            headers,
            withCredentials: true,
        });

        return response.json();
    }

    @task *uploadProofFile(file) {
        if (!file) {
            return;
        }

        this.bypassProof = false;
        this.proof = null;
        this.proofFiles = [file];

        try {
            this.proof = this.normalizeProofResponse(yield this.capturePhotoProof.perform());
            this.notifications.success('Photo proof of delivery uploaded.');
        } catch (error) {
            this.proof = null;
            this.proofFiles = [];
            this.notifications.serverError(error);
        }
    }

    @action toggleBypassProof(value) {
        this.bypassProof = value;
        if (value) {
            this.proofFiles = [];
            this.proof = null;
        }
    }

    normalizeProofResponse(response) {
        if (response?.proof) {
            return response.proof;
        }

        if (response?.data?.id || response?.data?.public_id) {
            return response.data;
        }

        return response;
    }
}
