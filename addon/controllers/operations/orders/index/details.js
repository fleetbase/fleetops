import Controller, { inject as controller } from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { isArray } from '@ember/array';
import { task } from 'ember-concurrency';
import { colorForId } from '../../../../utils/route-colors';

export default class OperationsOrdersIndexDetailsController extends Controller {
    @controller('operations.orders.index') index;
    @service('universe/menu-service') menuService;
    @service orderActions;
    @service orderSocketEvents;
    @service leafletMapManager;
    @service leafletLayerVisibilityManager;
    @service hostRouter;
    @service universe;
    @service sidebar;
    @tracked routingControl;

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
        yield this.hostRouter.refresh();
    }

    /**
     * Build the routing options object for this order, supplying the deterministic
     * route color, order status, and place models for enhanced waypoint markers.
     *
     * The `places` array is parallel to `routeWaypoints` — index 0 is the pickup
     * place, the last index is the dropoff, and any intermediate entries are stops.
     * This data is passed through to `addRoutingControl` so each waypoint marker
     * can display a rich popup with the place name, address, ETA, and status.
     */
    get routingOptions() {
        const order = this.model;
        const orderId = order.public_id;
        const status = order.status || 'dispatched';

        // Collect Place models from the payload waypoints (each waypoint has a `place`)
        let places = [];
        try {
            const payload = order.get ? order.get('payload') : order.payload;
            const waypoints = payload?.get ? payload.get('waypoints') : payload?.waypoints;
            if (isArray(waypoints)) {
                places = waypoints.map((wp) => (wp.get ? wp.get('place') : wp.place)).filter(Boolean);
            }
        } catch (_) {
            // Gracefully degrade — markers will render without popup content
        }

        return {
            orderId,
            status,
            places,
            color: colorForId(orderId),
        };
    }

    @action async setup() {
        // Change to map layout and display order route
        this.index.changeLayout('map');
        this.routingControl = await this.leafletMapManager.addRoutingControl(this.model.routeWaypoints, this.routingOptions);

        // Hide sidebar
        this.sidebar.hideNow();

        // Show & track driver assigned
        this.leafletLayerVisibilityManager.hideCategory('drivers');
        this.leafletLayerVisibilityManager.showModelLayer(this.model.driver_assigned);
        // Should we hide places & other vehicles?

        // Listen for this order events
        this.orderSocketEvents.start(
            this.model,
            async (_msg, { reloadable }) => {
                if (reloadable) {
                    await this.hostRouter.refresh();
                    this.leafletMapManager.replaceRoutingControl(this.model.routeWaypoints, this.routingControl, this.routingOptions);
                }
            },
            { debounceMs: 250 }
        );
    }
}
