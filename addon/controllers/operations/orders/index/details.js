import Controller, { inject as controller } from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { isArray } from '@ember/array';
import { task } from 'ember-concurrency';
import { colorForId, routeColorForStatus, routeStyleForStatus } from '../../../../utils/route-colors';
import { buildRoutePointMarkerPresentation, buildRoutePointsFromPayload } from '../../../../utils/route-visualization';

export default class OperationsOrdersIndexDetailsController extends Controller {
    @controller('operations.orders.index') index;
    @service('universe/menu-service') menuService;
    @service orderActions;
    @service orderSocketEvents;
    @service mapManager;
    @service leafletLayerVisibilityManager;
    @service hostRouter;
    @service universe;
    @service sidebar;
    @service abilities;
    @tracked routingControl;
    @tracked routingCompleted = false;
    @tracked realtimeOrderPublicId = null;
    @tracked proofReloadToken = 0;

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
                        id: 'edit-order-details',
                        text: 'Edit details',
                        icon: 'pencil',
                        permission: 'fleet-ops update order',
                        disabled: this.model.status === 'canceled',
                        fn: () => this.orderActions.editOrderDetails(this.model),
                    },
                    {
                        id: 'update-activity',
                        text: 'Update activity',
                        icon: 'signal',
                        permission: 'fleet-ops update order',
                        disabled: this.model.status === 'canceled',
                        fn: () =>
                            this.orderActions.updateActivity(this.model, {
                                onFinish: this.handleActivityModalFinish,
                            }),
                    },
                    {
                        text: this.model.has_driver_assigned ? 'Unassign driver' : 'Assign driver',
                        icon: this.model.has_driver_assigned ? 'user-xmark' : 'edit',
                        permission: 'fleet-ops assign-driver-for order',
                        disabled: this.model.has_driver_assigned ? !this.model.hasActiveStatus || !this.model.driver_assigned : !this.model.hasActiveStatus,
                        fn: () => (this.model.has_driver_assigned ? this.orderActions.unassignDriver(this.model) : this.orderActions.assignDriver(this.model)),
                    },
                    {
                        id: 'view-label',
                        text: 'View order label',
                        icon: 'file-invoice',
                        permission: 'fleet-ops view order',
                        fn: () => this.orderActions.viewLabel(this.model),
                    },
                    {
                        separator: true,
                    },
                    {
                        id: 'listen-to-socket-channel',
                        text: 'Listen to socket channel',
                        icon: 'headphones',
                        fn: () => this.hostRouter.transitionTo('console.developers.sockets.view', `order.${this.model.public_id}`),
                    },
                    {
                        id: 'view-metadata',
                        text: 'View metadata',
                        icon: 'table',
                        fn: () => this.orderActions.viewMetadata(this.model),
                    },
                    {
                        separator: true,
                    },
                    {
                        id: 'cancel',
                        text: 'Cancel order',
                        icon: 'ban',
                        class: 'text-danger',
                        permission: 'fleet-ops cancel order',
                        disabled: this.model.status === 'canceled',
                        fn: () => this.orderActions.cancel(this.model),
                    },
                    {
                        id: 'delete',
                        text: 'Delete order',
                        icon: 'trash',
                        class: 'text-danger',
                        permission: 'fleet-ops delete order',
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
        ].map((actionButton) => ({ ...actionButton, items: this.permittedMenuItems(actionButton.items) }));
    }

    /**
     * The panel header dropdown renders `items` as-is and ignores `permission`, so items the
     * user is not permitted to use are removed here, along with any separators left dangling.
     */
    permittedMenuItems(items = []) {
        const permitted = items.filter((item) => !item.permission || this.abilities.can(item.permission));
        const result = permitted.reduce((list, item) => {
            if (item.separator && (list.length === 0 || list[list.length - 1].separator)) {
                return list;
            }

            list.push(item);
            return list;
        }, []);

        if (result.length && result[result.length - 1].separator) {
            result.pop();
        }

        return result;
    }

    @action handleActivityModalFinish(options) {
        this.refresh.perform();

        if (options?.proofCreated) {
            this.proofReloadToken++;
        }
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
