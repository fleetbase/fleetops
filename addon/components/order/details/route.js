import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { debug } from '@ember/debug';
import { task } from 'ember-concurrency';
import { applyOptimizedIntermediateWaypoints, buildRouteOptimizationInput, canOptimizeIntermediateWaypoints } from '../../../utils/order-route-editing';
import { buildRoutePointsFromPayload } from '../../../utils/route-visualization';

export default class OrderDetailsRouteComponent extends Component {
    @service orderActions;
    @service modalsManager;
    @service fetch;
    @service notifications;
    @service routeEngine;
    @service routeOptimization;
    @service hostRouter;
    @service intl;

    constructor() {
        super(...arguments);
        this.loadTrackerData.perform();
    }

    get actionButtons() {
        return [
            this.showOptimizationSelect
                ? {
                      component: 'route-optimization-engine-select-button',
                      onClick: (service) => this.optimizeRouteWithService.perform(service),
                      isLoading: this.optimizeRouteWithService.isRunning,
                      disabled: !this.canOptimizeRoute,
                  }
                : {
                      type: 'magic',
                      icon: 'magic',
                      text: this.intl.t('order.fields.optimize-route'),
                      onClick: () => this.optimizeRoute.perform(),
                      permission: 'fleet-ops optimize order',
                      helpText: this.intl.t('order.fields.optimize-route-help-text'),
                      disabled: !this.canOptimizeRoute,
                      isLoading: this.optimizeRoute.isRunning,
                  },
            {
                icon: 'ellipsis-h',
                prefix: 'fas',
                triggerClass: 'fleetops-btn-xxs',
                items: [
                    {
                        text: 'Edit route',
                        icon: 'pencil',
                        disabled: !this.args.resource.hasActiveStatus,
                        onClick: () => {
                            this.orderActions.editRoute(this.args.resource);
                        },
                    },
                    this.isMultipleWaypointOrder
                        ? {
                              text: 'Change destination',
                              icon: 'location-dot',
                              disabled: !this.args.resource.hasActiveStatus,
                              onClick: () => {
                                  this.changeDestination();
                              },
                          }
                        : null,
                ].filter(Boolean),
            },
        ].filter(Boolean);
    }

    get canOptimizeRoute() {
        return canOptimizeIntermediateWaypoints(this.args.resource?.payload);
    }

    get isMultipleWaypointOrder() {
        const waypoints = this.args.resource?.payload?.waypoints;
        const waypointCount = typeof waypoints?.toArray === 'function' ? waypoints.toArray().length : Array.isArray(waypoints) ? waypoints.length : (waypoints?.length ?? 0);

        return Boolean(this.args.resource?.hasIntermediateWaypoints || waypointCount > 0);
    }

    get showOptimizationSelect() {
        return this.routeOptimization.availableEngines.length > 1;
    }

    get trackerData() {
        return this.args.resource?.tracker_data;
    }

    get hasTrackingRouteSummary() {
        return Boolean(this.trackerData?.route || this.trackerData?.eta);
    }

    get hasTrackingDistance() {
        return this.trackerData?.route?.distance_m !== null && this.trackerData?.route?.distance_m !== undefined;
    }

    get hasCompletionEta() {
        return Boolean(this.trackerData?.eta?.completion_at);
    }

    get routeStopsCount() {
        return buildRoutePointsFromPayload(this.args.resource?.payload).length;
    }

    get hasTrackingDuration() {
        return this.trackerData?.route?.duration_in_traffic_s !== null && this.trackerData?.route?.duration_in_traffic_s !== undefined;
    }

    get trackingDurationSeconds() {
        return this.trackerData?.route?.duration_in_traffic_s ?? this.trackerData?.route?.duration_s;
    }

    get hasRouteSummaryLine() {
        return this.hasTrackingRouteSummary || this.routeStopsCount > 0;
    }

    @task *loadTrackerData() {
        if (!this.args.resource || this.args.resource.tracker_data || typeof this.args.resource.loadTrackerData !== 'function') {
            return;
        }

        try {
            yield this.args.resource.loadTrackerData();
        } catch (err) {
            debug('Failed to load order tracker data for route: ' + err.message);
        }
    }

    @task *optimizeRouteWithService(service) {
        const order = this.args.resource;
        const optimizationInput = buildRouteOptimizationInput(order);

        if (!service) {
            this.notifications.error('No route optimization engine was selected.');
            return;
        }

        try {
            const result = yield this.routeOptimization.optimize(service, {
                context: 'order_details_route',
                ...optimizationInput,
            });

            yield this.persistOptimizedRoute(result);
        } catch (err) {
            this.notifications.error(err.message ?? this.intl.t('fleet-ops.operations.orders.index.new.route-error'));
        }
    }

    @task *optimizeRoute() {
        const order = this.args.resource;
        const optimizationInput = buildRouteOptimizationInput(order);
        const service = this.routeEngine.getOptimizationEngine('osrm');

        try {
            const result = yield this.routeOptimization.optimize(service, {
                context: 'order_details_route',
                ...optimizationInput,
            });

            yield this.persistOptimizedRoute(result);
        } catch (_err) {
            this.notifications.error(this.intl.t('fleet-ops.operations.orders.index.new.route-error'));
        }
    }

    *persistOptimizedRoute({ sortedWaypoints }) {
        applyOptimizedIntermediateWaypoints(this.args.resource.payload, sortedWaypoints);
        yield this.orderActions.saveRoute.perform(this.args.resource);
        yield this.hostRouter.refresh();
    }

    @action changeDestination() {
        this.modalsManager.show('modals/order-change-destination', {
            title: 'Change destination',
            order: this.args.resource,
            acceptButtonText: 'Change destination',
            declineButtonText: 'Close',
            onChange: async () => {
                if (typeof this.args.resource?.reload === 'function') {
                    await this.args.resource.reload();
                }

                if (typeof this.args.resource?.set === 'function') {
                    this.args.resource.set('tracker_data', null);
                }

                if (typeof this.args.resource?.loadTrackerData === 'function') {
                    await this.args.resource.loadTrackerData();
                }

                await this.hostRouter.refresh();
            },
        });
    }

    @action viewStopActivity(stop) {
        this.modalsManager.show('modals/stop-activity', {
            title: 'Stop activity',
            stop,
            order: this.args.resource,
            hideAcceptButton: true,
            declineButtonText: 'Close',
        });
    }

    @action async viewWaypointLabel(stop) {
        const waypoint = stop?.place ?? stop;
        const waypointId = waypoint?.waypoint_public_id ?? stop?.waypoint_public_id;
        if (!waypointId) {
            this.notifications.warning('Labels are only available for waypoint stops.');
            return;
        }

        this.modalsManager.show(`modals/order-label`, {
            title: 'Waypoint Label',
            modalClass: 'modal-xl',
            acceptButtonText: 'Done',
            hideDeclineButton: true,
        });

        try {
            // eslint-disable-next-line no-undef
            const fileReader = new FileReader();
            const { data: pdfStream } = await this.fetch.get(`orders/label/${waypointId}?format=base64`);
            // eslint-disable-next-line no-undef
            const base64 = await fetch(`data:application/pdf;base64,${pdfStream}`);
            const blob = await base64.blob();
            fileReader.onload = (event) => {
                const data = event.target.result;
                this.modalsManager.setOption('data', data);
            };
            fileReader.readAsDataURL(blob);
        } catch (err) {
            this.notifications.error('Failed to load waypoint label.');
            debug('Error loading waypoint label data: ' + err.message);
        }
    }
}
