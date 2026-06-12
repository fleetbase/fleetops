import Controller from '@ember/controller';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { isArray } from '@ember/array';

export default class ManagementDriverIndexDetailsController extends Controller {
    @service('universe/menu-service') menuService;
    @service driverActions;
    @service issueActions;
    @service hostRouter;
    @service intl;

    get tabs() {
        const registeredTabs = this.menuService.getMenuItems('fleet-ops:component:driver:details');
        return [
            {
                route: 'management.drivers.index.details.index',
                label: this.intl.t('common.overview'),
            },
            {
                route: 'management.drivers.index.details.positions',
                label: this.intl.t('common.positions'),
            },
            {
                route: 'management.drivers.index.details.schedule',
                label: this.intl.t('common.schedule'),
            },
            {
                route: 'management.drivers.index.details.activity',
                label: this.intl.t('common.activity'),
            },
            ...(isArray(registeredTabs) ? registeredTabs : []),
        ];
    }

    hasAssignedVehicle(driver) {
        return Boolean(driver?.vehicle_uuid || driver?.vehicle?.id || driver?.vehicle?.uuid || driver?.vehicle_name);
    }

    get actionButtons() {
        return [
            {
                icon: 'pencil',
                fn: () => this.hostRouter.transitionTo('console.fleet-ops.management.drivers.index.edit', this.model),
                permission: 'fleet-ops update driver',
            },
            {
                icon: 'ellipsis-h',
                iconPrefix: 'fas',
                renderInPlace: true,
                items: [
                    {
                        text: this.intl.t('driver.actions.assign-order'),
                        icon: 'clipboard-list',
                        fn: () => this.driverActions.assignOrder(this.model),
                        permission: 'fleet-ops assign-order-for driver',
                    },
                    ...(Number(this.model.assigned_orders_count) > 0
                        ? [
                              {
                                  text: this.intl.t('driver.actions.unassign-orders'),
                                  icon: 'user-minus',
                                  fn: () => this.driverActions.unassignOrders(this.model),
                                  permission: 'fleet-ops assign-order-for driver',
                              },
                          ]
                        : []),
                    {
                        separator: true,
                    },
                    {
                        text: this.intl.t('driver.actions.assign-vehicle'),
                        icon: 'car',
                        fn: () => this.driverActions.assignVehicle(this.model),
                        permission: 'fleet-ops assign-vehicle-for driver',
                    },
                    ...(this.hasAssignedVehicle(this.model)
                        ? [
                              {
                                  text: this.intl.t('driver.actions.unassign-vehicle'),
                                  icon: 'link-slash',
                                  fn: () => this.driverActions.unassignVehicle(this.model),
                                  permission: 'fleet-ops assign-vehicle-for driver',
                              },
                          ]
                        : []),
                    {
                        separator: true,
                    },
                    {
                        text: this.intl.t('driver.actions.locate-driver'),
                        icon: 'location-dot',
                        fn: () => this.driverActions.locate(this.model),
                        permission: 'fleet-ops view driver',
                    },
                    {
                        text: this.intl.t('driver.actions.create-issue'),
                        icon: 'triangle-exclamation',
                        fn: () => this.createIssue(this.model),
                        permission: 'fleet-ops create issue',
                    },
                ],
            },
        ];
    }

    @action createIssue(driver) {
        this.issueActions.modal.create({
            driver,
            driver_uuid: driver.id,
            title: this.intl.t('driver.prompts.issue-title', { driverName: driver.name }),
        });
    }
}
