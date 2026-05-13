import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { debug } from '@ember/debug';
import { task } from 'ember-concurrency';
import { applyOptimizedIntermediateWaypoints, buildRouteOptimizationInput, canOptimizeIntermediateWaypoints } from '../../../utils/order-route-editing';

export default class OrderDetailsRouteComponent extends Component {
    @service orderActions;
    @service modalsManager;
    @service fetch;
    @service notifications;
    @service routeEngine;
    @service routeOptimization;
    @service hostRouter;
    @service intl;

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
                type: 'default',
                text: 'Edit',
                icon: 'pencil',
                iconPrefix: 'fas',
                permission: 'fleet-ops update-route-for order',
                disabled: this.args.resource.status === 'canceled',
                onClick: () => {
                    this.orderActions.editRoute(this.args.resource);
                },
            },
        ];
    }

    get canOptimizeRoute() {
        return canOptimizeIntermediateWaypoints(this.args.resource?.payload);
    }

    get showOptimizationSelect() {
        return this.routeOptimization.availableEngines.length > 1;
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

    @action async viewWaypointLabel(waypoint) {
        this.modalsManager.show(`modals/order-label`, {
            title: 'Waypoint Label',
            modalClass: 'modal-xl',
            acceptButtonText: 'Done',
            hideDeclineButton: true,
        });

        try {
            // eslint-disable-next-line no-undef
            const fileReader = new FileReader();
            const { data: pdfStream } = await this.fetch.get(`orders/label/${waypoint.waypoint_public_id}?format=base64`);
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
