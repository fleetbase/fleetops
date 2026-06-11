import Controller from '@ember/controller';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { isArray } from '@ember/array';

export default class ManagementVehiclesIndexDetailsController extends Controller {
    @service vehicleActions;
    @service issueActions;
    @service('universe/menu-service') menuService;
    @service hostRouter;
    @service intl;

    get tabs() {
        const registeredTabs = this.menuService.getMenuItems('fleet-ops:component:vehicle:details');
        return [
            {
                route: 'management.vehicles.index.details.index',
                label: 'Overview',
            },
            {
                route: 'management.vehicles.index.details.positions',
                label: 'Positions',
            },
            {
                route: 'management.vehicles.index.details.devices',
                label: 'Devices',
            },
            {
                route: 'management.vehicles.index.details.schedules',
                label: 'Schedules',
            },
            {
                route: 'management.vehicles.index.details.work-orders',
                label: 'Work Orders',
            },
            {
                route: 'management.vehicles.index.details.maintenance-history',
                label: 'Maintenance',
            },
            ...(isArray(registeredTabs) ? registeredTabs : []),
        ];
    }

    get actionButtons() {
        return [
            {
                icon: 'pencil',
                fn: () => this.hostRouter.transitionTo('console.fleet-ops.management.vehicles.index.edit', this.model),
                permission: 'fleet-ops update vehicle',
            },
            {
                // Actions dropdown — mirrors the row-level actions from the vehicles index table
                icon: 'ellipsis-h',
                iconPrefix: 'fas',
                renderInPlace: true,
                items: [
                    {
                        text: this.intl.t('vehicle.actions.locate-vehicle'),
                        icon: 'location-dot',
                        fn: () => this.vehicleActions.locate(this.model),
                        permission: 'fleet-ops view vehicle',
                    },
                    {
                        text: 'Attach Device',
                        icon: 'link',
                        fn: () => this.vehicleActions.attachDevice(this.model),
                        permission: 'fleet-ops update vehicle',
                    },
                    {
                        separator: true,
                    },
                    {
                        text: this.intl.t('vehicle.actions.schedule-maintenance'),
                        icon: 'calendar-check',
                        fn: () => this.vehicleActions.scheduleMaintenance(this.model),
                        permission: 'fleet-ops create maintenance-schedule',
                    },
                    {
                        text: this.intl.t('vehicle.actions.create-work-order'),
                        icon: 'clipboard-list',
                        fn: () => this.vehicleActions.createWorkOrder(this.model),
                        permission: 'fleet-ops create work-order',
                    },
                    {
                        text: this.intl.t('vehicle.actions.log-maintenance'),
                        icon: 'wrench',
                        fn: () => this.vehicleActions.logMaintenance(this.model),
                        permission: 'fleet-ops create maintenance',
                    },
                    {
                        text: 'Create Issue',
                        icon: 'triangle-exclamation',
                        fn: () => this.createIssue(this.model),
                        permission: 'fleet-ops create issue',
                    },
                    {
                        separator: true,
                    },
                    {
                        text: this.intl.t('common.delete-resource', { resource: this.intl.t('resource.vehicle') }),
                        icon: 'trash',
                        fn: () =>
                            this.vehicleActions.delete(this.model, {
                                onConfirm: () => {
                                    this.hostRouter.transitionTo('console.fleet-ops.management.vehicles.index');
                                },
                            }),
                        permission: 'fleet-ops delete vehicle',
                        class: 'text-red-500 hover:text-red-600',
                    },
                ],
            },
        ];
    }

    @action createIssue(vehicle) {
        this.issueActions.modal.create({
            vehicle,
            vehicle_uuid: vehicle.id,
            title: `Issue reported for ${vehicle.displayName ?? vehicle.name}`,
        });
    }
}
