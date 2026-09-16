import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { isArray } from '@ember/array';

export default class ManagementVehiclesIndexDetailsController extends Controller {
    @service vehicleActions;
    @service('universe/menu-service') menuService;
    @service hostRouter;
    @service intl;

    get tabs() {
        const registeredTabs = this.menuService.getMenuItems('fleet-ops:component:vehicle:details');
        return [
            {
                id: 'index',
                route: 'management.vehicles.index.details.index',
                label: 'Overview',
            },
            {
                id: 'positions',
                route: 'management.vehicles.index.details.positions',
                label: 'Positions',
            },
            {
                id: 'devices',
                route: 'management.vehicles.index.details.devices',
                label: 'Devices',
            },
            {
                id: 'trailers',
                route: 'management.vehicles.index.details.trailers',
                label: this.intl.t('resource.trailers'),
            },
            {
                id: 'equipment',
                route: 'management.vehicles.index.details.equipment',
                label: this.intl.t('resource.equipment'),
            },
            {
                id: 'schedules',
                route: 'management.vehicles.index.details.schedules',
                label: 'Schedules',
            },
            {
                id: 'work-orders',
                route: 'management.vehicles.index.details.work-orders',
                label: 'Work Orders',
            },
            {
                id: 'maintenance-history',
                route: 'management.vehicles.index.details.maintenance-history',
                label: 'Maintenance',
            },
            {
                id: 'inspections',
                route: 'management.vehicles.index.details.inspections',
                label: this.intl.t('resource.inspections'),
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
                // Actions dropdown — shared with the vehicle context panel
                icon: 'ellipsis-h',
                iconPrefix: 'fas',
                renderInPlace: true,
                items: this.vehicleActions.detailsMenuItems(this.model, {
                    onDeleted: () => this.hostRouter.transitionTo('console.fleet-ops.management.vehicles.index'),
                }),
            },
        ];
    }
}
