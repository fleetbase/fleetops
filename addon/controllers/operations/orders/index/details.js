import Controller, { inject as controller } from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { isArray } from '@ember/array';
import { task } from 'ember-concurrency';
import { colorForId, routeColorForStatus, routeStyleForStatus } from '../../../../utils/route-colors';
import { buildRoutePointMarkerPresentation, buildRoutePointsFromPayload } from '../../../../utils/route-visualization';

export default class OperationsOrdersIndexDetailsController extends Controller {
    @controller('operations.orders.index') index;
    @service('universe/menu-service') menuService;
    @service orderActions;
    @service recurringOrderScheduleActions;
    @service orderSocketEvents;
    @service mapManager;
    @service leafletLayerVisibilityManager;
    @service hostRouter;
    @service universe;
    @service sidebar;
    @tracked routingControl;
    @tracked routingCompleted = false;
    @tracked realtimeOrderPublicId = null;

    get routePoints() {
        return buildRoutePointsFromPayload(this.model?.payload);
    }

    get routeMarkerFactory() {
        const routeColor = colorForId(this.model.public_id ?? this.model.id ?? 'order-route');

        return (_waypoint, index) => {
            return buildRoutePointMarkerPresentation(this.routePoints[index], routeColor);
        };
    }

    get routeMarkerWaypoints() {
        return this.routePoints.map(({ place }) => [place.latitude, place.longitude]);
    }

    get routeStatus() {
        return this.model.status ?? 'created';
    }

    get routePolylineOptions() {
        const color = routeColorForStatus(this.routeStatus);
        const styles = routeStyleForStatus(this.routeStatus, color);

        return {
            color,
            weight: styles.at(-1)?.weight ?? 4,
            opacity: styles.at(-1)?.opacity ?? 0.85,
            styles,
        };
    }

    get routeControlTag() {
        return this.model?.public_id ? `order-details:${this.model.public_id}` : null;
    }

    get routingControlOptions() {
        return {
            tag: this.routeControlTag,
            color: this.routePolylineOptions.color,
            status: this.routeStatus,
            markerWaypoints: this.routeMarkerWaypoints,
            polylineOptions: this.routePolylineOptions,
            createMarker: this.routeMarkerFactory,
            onRouteFound: this.handleRoutingComplete,
            onRoutingError: this.handleRoutingComplete,
        };
    }

    get tabs() {
        const registeredTabs = this.menuService.getMenuItems('fleet-ops:component:order:details');
        return [
            {
                route: 'operations.orders.index.details.index',
                label: 'Overview',
                icon: 'folder-open',
            },
            ...(isArray(registeredTabs) ? registeredTabs : []),
        ];
    }

    get actionButtons() {
        return [
            {
                items: [
                    {
                        text: 'Edit details',
                        icon: 'pencil',
                        disabled: this.model.status === 'canceled',
                        fn: () => this.orderActions.editOrderDetails(this.model),
                    },
                    {
                        text: 'Update activity',
                        icon: 'signal',
                        disabled: this.model.status === 'canceled',
                        fn: () =>
                            this.orderActions.updateActivity(this.model, {
                                onFinish: () => {
                                    this.refresh.perform();
                                },
                            }),
                    },
                    {
                        text: 'Unassign driver',
                        icon: 'user-xmark',
                        disabled: this.model.status === 'canceled' || !this.model.driver_assigned,
                        fn: () => this.orderActions.unassignDriver(this.model),
                    },
                    {
                        text: 'View order label',
                        icon: 'file-invoice',
                        fn: () => this.orderActions.viewLabel(this.model),
                    },
                    {
                        separator: true,
                    },
                    {
                        text: 'Listen to socket channel',
                        icon: 'headphones',
                        fn: () => this.hostRouter.transitionTo('console.developers.sockets.view', `order.${this.model.public_id}`),
                    },
                    {
                        text: 'View metadata',
                        icon: 'table',
                        fn: () => this.orderActions.viewMetadata(this.model),
                    },
                    {
                        text: 'Make this recurring...',
                        icon: 'arrows-rotate',
                        fn: () => this.recurringOrderScheduleActions.transition.createFromOrder(this.model),
                    },
                    {
                        separator: true,
                    },
                    {
                        text: 'Cancel order',
                        icon: 'ban',
                        class: 'text-danger',
                        disabled: this.model.status === 'canceled',
                        fn: () => this.orderActions.cancel(this.model),
                    },
                    {
                        text: 'Delete order',
                        icon: 'trash',
                        class: 'text-danger',
                        fn: () =>
                            this.orderActions.delete(this.model, {
                                taskOptions: {
                                    callback: () => {
                                        this.hostRouter.transitionTo('console.fleet-ops.operations.orders.index');
                                    },
                                },
                            }),
                    },
                ].filter(Boolean),
            },
        ];
    }

    @task *refresh() {
        try {
            yield this.hostRouter.refresh();
        } catch (error) {
            return error;
        }
    }

    handleRoutingComplete = () => {
        this.routingCompleted = true;
    };

    async setupRealtime() {
        const currentOrderPublicId = this.model?.public_id;
        if (!currentOrderPublicId) {
            return;
        }

        if (this.realtimeOrderPublicId && this.realtimeOrderPublicId !== currentOrderPublicId) {
            this.orderSocketEvents.stop({ public_id: this.realtimeOrderPublicId });
        }

        this.realtimeOrderPublicId = currentOrderPublicId;

        this.orderSocketEvents.start(
            this.model,
            async (_msg, { reloadable }) => {
                if (reloadable) {
                    await this.hostRouter.refresh();
                }
            },
            { debounceMs: 250 }
        );
    }

    async syncRoutingControl() {
        this.routingCompleted = false;
        this.teardownRoutingControls();

        this.routingControl = await this.mapManager.addRoutingControl(this.model.routeWaypoints, this.routingControlOptions);

        if (!this.routingControl) {
            this.routingCompleted = true;
        }
    }

    syncMapContext() {
        this.leafletLayerVisibilityManager.hideCategory('drivers');
        this.leafletLayerVisibilityManager.showModelLayer(this.model.driver_assigned);
    }

    teardownRoutingControls() {
        if (this.routeControlTag) {
            this.mapManager.clearRoutingControlsByTag(this.routeControlTag);
        } else if (this.routingControl) {
            this.mapManager.removeRoutingControl(this.routingControl);
        }

        this.routingControl = undefined;
        this.routingCompleted = false;
    }

    teardownRealtime() {
        if (this.realtimeOrderPublicId) {
            this.orderSocketEvents.stop({ public_id: this.realtimeOrderPublicId });
            this.realtimeOrderPublicId = null;
        }
    }

    async setupDetailsSession() {
        this.index.changeLayout('map');
        await this.setupRealtime();
        await this.syncRoutingControl();
        this.syncMapContext();
    }
}
